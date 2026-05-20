<?php

namespace ZyCrypt\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use ZyCrypt\Laravel\Services\LicenseValidator;
use ZyCrypt\Laravel\Services\BundleInstaller;

class InstallCommand extends Command
{
    protected $signature   = 'zycrypt:install {--theme= : Slug tema (opsional, ditampilkan sebagai pilihan jika tidak diset)}';
    protected $description = 'Install ZyCrypt secara penuh: validasi lisensi, unduh tema, setup route, npm install, dan database guard';

    public function handle(LicenseValidator $validator, BundleInstaller $installer): int
    {
        $this->printBanner();

        // ── Step 1: Konfigurasi ───────────────────────────────────────────────
        $this->task('Memeriksa konfigurasi', function () {
            if (! config('zycrypt.license_key')) {
                throw new \RuntimeException('ZYCRYPT_LICENSE_KEY belum diset di .env');
            }
            if (! config('zycrypt.shared_secret')) {
                throw new \RuntimeException('ZYCRYPT_SHARED_SECRET belum diset di .env');
            }
        });

        $this->line('    Key    : ' . substr(config('zycrypt.license_key'), 0, 10) . '...');
        $this->line('    Server : ' . config('zycrypt.server_url'));
        $this->newLine();

        // ── Step 2: Validasi lisensi ──────────────────────────────────────────
        $result = null;
        $this->task('Memvalidasi lisensi ke server', function () use ($validator, &$result) {
            $result = $validator->validate();
            if (! $result['valid']) {
                throw new \RuntimeException(
                    ($result['reason'] ?? 'unknown') .
                    (! empty($result['detail']) ? ' — ' . $result['detail'] : '')
                );
            }
        });

        $data = $result['data'];
        $this->line('    Plan    : ' . ($data['plan'] ?? '-'));
        $this->line('    Expired : ' . ($data['is_lifetime'] ? 'Lifetime' : ($data['expires_at'] ?? '-')));
        $this->newLine();

        // ── Step 3: Lock file ─────────────────────────────────────────────────
        $this->task('Menyimpan lock file', fn() => $validator->writeLock($data));

        // ── Step 4: Publish config & views ────────────────────────────────────
        $this->task('Mempublish config & views', function () {
            $this->callSilently('vendor:publish', ['--tag' => 'zycrypt-config', '--force' => true]);
            $this->callSilently('vendor:publish', ['--tag' => 'zycrypt-views',  '--force' => true]);
        });

        // ── Step 5: Pilih & download tema ─────────────────────────────────────
        $themeSlug = $this->resolveTheme($installer);

        if ($themeSlug) {
            $this->newLine();
            $this->info("  Mengunduh tema: {$themeSlug}");

            $success = $installer->install($themeSlug, function (string $msg) {
                $this->line('    ' . $msg);
            });

            if (! $success) {
                $this->error('  ✗ Gagal mengunduh tema.');
                return self::FAILURE;
            }

            // ── Step 6: Patch app.ts ──────────────────────────────────────────
            $this->task('Menambahkan ZyCrypt plugin ke app.ts', fn() => $this->patchAppTs());

            // ── Step 7: Setup routes ──────────────────────────────────────────
            $this->task('Menyiapkan routes', fn() => $this->setupRoutes());

            // ── Step 8: Install npm dependencies ─────────────────────────────
            $this->newLine();
            $this->task('Menjalankan npm install', function () {
                $result = Process::path(base_path())->timeout(300)->run('npm install');
                if (! $result->successful()) {
                    throw new \RuntimeException($result->errorOutput());
                }
            });

            // ── Step 9: Install zycrypt-vue ───────────────────────────────────
            $this->task('Menginstall zycrypt-vue', function () {
                $pkgPath = config('zycrypt.npm_package_path', 'zycrypt-vue');
                $result  = Process::path(base_path())->timeout(120)->run("npm install {$pkgPath} --save");
                if (! $result->successful()) {
                    throw new \RuntimeException($result->errorOutput());
                }
            });

            // ── Step 10: Build frontend ───────────────────────────────────────
            $this->task('Build frontend (npm run build)', function () {
                $result = Process::path(base_path())->timeout(300)->run('npm run build');
                if (! $result->successful()) {
                    throw new \RuntimeException($result->errorOutput());
                }
            });
        }

        // ── Step 11: Database guard ───────────────────────────────────────────
        $this->newLine();
        $this->task('Memasang database guard', function () {
            $this->callSilently('zycrypt:guard', ['action' => 'install']);
        });

        // ── Selesai ───────────────────────────────────────────────────────────
        $this->newLine();
        $this->printSuccess($themeSlug);

        return self::SUCCESS;
    }

    private function task(string $label, callable $fn): void
    {
        $this->line("  <fg=cyan>→</> {$label}...", null, 'v');
        $this->output->write("  → {$label}...");

        try {
            $fn();
            $this->output->writeln(' <fg=green>✓</>');
        } catch (\Throwable $e) {
            $this->output->writeln(' <fg=red>✗</>');
            $this->newLine();
            $this->error('    ' . $e->getMessage());
            $this->newLine();
            throw $e;
        }
    }

    private function resolveTheme(BundleInstaller $installer): ?string
    {
        $themeSlug = $this->option('theme');
        if ($themeSlug) {
            return $themeSlug;
        }

        $this->newLine();
        $this->output->write('  → Mengambil daftar tema...');
        $themes = $installer->availableThemes();
        $this->output->writeln(' <fg=green>✓</>');

        if (empty($themes)) {
            $this->warn('  ⚠ Tidak ada tema tersedia untuk lisensi ini.');
            return null;
        }

        if (count($themes) === 1) {
            $t = $themes[0];
            $this->line("  ✓ Tema dipilih otomatis: {$t['name']} (v{$t['version']})");
            return $t['slug'];
        }

        $choices = [];
        $map     = [];
        foreach ($themes as $t) {
            $label       = sprintf('%s  (v%s)', $t['name'], $t['version']);
            $choices[]   = $label;
            $map[$label] = $t['slug'];
        }
        $choices[] = 'Lewati — jangan install tema';

        $this->newLine();
        $selected = $this->choice('  Pilih tema yang akan diinstall', $choices, 0);

        if ($selected === 'Lewati — jangan install tema') {
            return null;
        }

        return $map[$selected];
    }

    private function patchAppTs(): void
    {
        $path = base_path('resources/js/app.ts');
        if (! file_exists($path)) {
            return;
        }

        $content = file_get_contents($path);
        if (str_contains($content, '@ziaulkamal/zycrypt-vue')) {
            return;
        }

        $pkgName = config('zycrypt.npm_package_path', '@ziaulkamal/zycrypt-vue');
        $import  = "import ZyCrypt from '{$pkgName}';\n";
        $useStmt = "            .use(ZyCrypt, {\n"
                 . "                serverUrl:  '/zycrypt/token',\n"
                 . "                graceHours: " . config('zycrypt.grace_hours', 24) . ",\n"
                 . "            })\n";

        $content = preg_replace(
            "/(import\s+['\"][^'\"]+['\"];?\s*\n)/",
            "$1" . $import,
            $content,
            1
        );

        $content = str_replace('.mount(el)', $useStmt . '            .mount(el)', $content);

        file_put_contents($path, $content);
    }

    private function setupRoutes(): void
    {
        $webPhp = base_path('routes/web.php');

        if (! file_exists($webPhp)) {
            return;
        }

        $current = file_get_contents($webPhp);

        if (str_contains($current, 'zycrypt-routes')) {
            return;
        }

        $marker = "\n// @zycrypt-routes\nif (file_exists(__DIR__ . '/zycrypt.php')) {\n    require __DIR__ . '/zycrypt.php';\n}\n";

        file_put_contents($webPhp, $current . $marker);
    }

    private function printBanner(): void
    {
        $this->newLine();
        $this->line('  <fg=blue>╔══════════════════════════════════════╗</>');
        $this->line('  <fg=blue>║</>    ZyCrypt — Auto Installer        <fg=blue>║</>');
        $this->line('  <fg=blue>╚══════════════════════════════════════╝</>');
        $this->newLine();
    }

    private function printSuccess(?string $themeSlug): void
    {
        $this->line('  <fg=green>╔══════════════════════════════════════╗</>');
        $this->line('  <fg=green>║</>  ✓  Instalasi ZyCrypt selesai!     <fg=green>║</>');
        $this->line('  <fg=green>╚══════════════════════════════════════╝</>');
        $this->newLine();

        if ($themeSlug) {
            $this->line("  Tema      : <fg=green>{$themeSlug}</>");
        }

        $this->line('  Lisensi   : <fg=green>Valid</>');
        $this->line('  DB Guard  : <fg=green>Aktif</>');
        $this->line('  Frontend  : <fg=green>Built</>');
        $this->newLine();
        $this->line('  Jalankan  : <fg=cyan>php artisan serve</>');
        $this->newLine();
    }
}
