<?php

namespace ZyCrypt\Laravel\Console;

use Illuminate\Console\Command;
use ZyCrypt\Laravel\Services\LicenseValidator;
use ZyCrypt\Laravel\Services\BundleInstaller;

class InstallCommand extends Command
{
    protected $signature   = 'zycrypt:install {--theme= : Slug tema yang akan diunduh}';
    protected $description = 'Validasi lisensi dan unduh bundle tema dari ZyCrypt server';

    public function handle(LicenseValidator $validator, BundleInstaller $installer): int
    {
        $this->printBanner();

        // ── Step 1: Cek konfigurasi ──────────────────────────────────────────
        $this->info('  Memeriksa konfigurasi...');

        if (! config('zycrypt.license_key')) {
            $this->error('  ✗ ZYCRYPT_LICENSE_KEY belum diset di .env');
            $this->line('    Tambahkan: ZYCRYPT_LICENSE_KEY=ZYC-XXXX-XXXX-XXXX-XXXX');
            return self::FAILURE;
        }

        if (! config('zycrypt.shared_secret')) {
            $this->error('  ✗ ZYCRYPT_SHARED_SECRET belum diset di .env');
            return self::FAILURE;
        }

        $this->line('  ✓ Konfigurasi ditemukan');
        $this->line('    Key    : ' . substr(config('zycrypt.license_key'), 0, 10) . '...');
        $this->line('    Server : ' . config('zycrypt.server_url'));
        $this->newLine();

        // ── Step 2: Validasi lisensi ke server ──────────────────────────────
        $this->info('  Memvalidasi lisensi ke server...');
        $result = $validator->validate();

        if (! $result['valid']) {
            $this->newLine();
            $this->error('  ✗ Lisensi tidak valid!');
            $this->line('    Alasan : ' . ($result['reason'] ?? 'unknown'));
            if (! empty($result['detail'])) {
                $this->line('    Detail : ' . $result['detail']);
            }
            $this->newLine();
            $this->line('  Hubungi vendor untuk mendapatkan lisensi yang valid.');
            return self::FAILURE;
        }

        $data = $result['data'];
        $this->line('  ✓ Lisensi valid!');
        $this->line('    Plan     : ' . ($data['plan'] ?? '-'));
        $this->line('    Expired  : ' . ($data['is_lifetime'] ? 'Lifetime' : ($data['expires_at'] ?? '-')));
        $this->newLine();

        // ── Step 3: Tulis lock file ──────────────────────────────────────────
        $validator->writeLock($data);
        $this->line('  ✓ Lock file tersimpan');

        // ── Step 4: Publish config & views ──────────────────────────────────
        $this->info('  Mempublish assets...');
        $this->call('vendor:publish', ['--tag' => 'zycrypt-config', '--force' => true]);
        $this->call('vendor:publish', ['--tag' => 'zycrypt-views', '--force' => true]);

        // ── Step 5: Download bundle tema (jika ada) ──────────────────────────
        $themeSlug = $this->option('theme');
        if ($themeSlug) {
            $this->newLine();
            $this->info('  Mengunduh bundle tema: ' . $themeSlug);

            $success = $installer->install($themeSlug, function (string $msg) {
                $this->line('  ' . $msg);
            });

            if (! $success) {
                $this->error('  ✗ Gagal mengunduh tema. Coba jalankan ulang atau hubungi vendor.');
                return self::FAILURE;
            }

            // Patch app.ts untuk inject ZyCrypt Vue plugin
            $this->patchAppTs();
        }

        // ── Step 6: Pasang database guard (opsional) ─────────────────────────
        $this->newLine();
        $this->info('  Database Guard (Lapisan Keamanan Tambahan)');
        $this->line('  Guard ini menyisipkan trigger di database sehingga aplikasi tetap');
        $this->line('  terlindungi meskipun package ZyCrypt dihapus.');
        $this->newLine();

        if ($this->confirm('  Pasang database guard sekarang?', true)) {
            $exitCode = $this->call('zycrypt:guard', ['action' => 'install']);
            if ($exitCode !== self::SUCCESS) {
                $this->warn('  ⚠ Database guard gagal dipasang. Lanjutkan tanpa guard.');
            }
        } else {
            $this->line('  Database guard dilewati. Jalankan nanti: php artisan zycrypt:guard install');
        }

        // ── Selesai ──────────────────────────────────────────────────────────
        $this->newLine();
        $this->printSuccess();

        return self::SUCCESS;
    }

    private function patchAppTs(): void
    {
        $appTsPath = base_path('resources/js/app.ts');
        if (! file_exists($appTsPath)) {
            $this->warn('  ⚠ resources/js/app.ts tidak ditemukan, patch dilewati.');
            return;
        }

        $content = file_get_contents($appTsPath);

        // Cek sudah dipatch sebelumnya
        if (str_contains($content, 'zycrypt-vue')) {
            $this->line('  ✓ app.ts sudah terpatch sebelumnya');
            return;
        }

        // Sisipkan import ZyCrypt setelah baris import pertama
        $import  = "import ZyCrypt from 'zycrypt-vue';\n";
        $useStmt = "            .use(ZyCrypt, {\n"
                 . "                licenseKey: import.meta.env.VITE_ZYCRYPT_LICENSE_KEY,\n"
                 . "                serverUrl:  import.meta.env.VITE_ZYCRYPT_SERVER_URL,\n"
                 . "            })\n";

        // Tambah import di baris kedua (setelah import pertama)
        $content = preg_replace(
            "/(import\s+['\"][^'\"]+['\"];?\s*\n)/",
            "$1" . $import,
            $content,
            1
        );

        // Tambah .use(ZyCrypt) sebelum .mount(el)
        $content = str_replace('.mount(el)', $useStmt . '            .mount(el)', $content);

        file_put_contents($appTsPath, $content);
        $this->line('  ✓ resources/js/app.ts berhasil dipatch');
        $this->line('  ⚠ Jalankan: npm install zycrypt-vue --save');
    }

    private function printBanner(): void
    {
        $this->newLine();
        $this->line('  ╔══════════════════════════════════════╗');
        $this->line('  ║    ZyCrypt — License Installer       ║');
        $this->line('  ╚══════════════════════════════════════╝');
        $this->newLine();
    }

    private function printSuccess(): void
    {
        $this->line('  ╔══════════════════════════════════════╗');
        $this->line('  ║  ✓  Instalasi ZyCrypt selesai!       ║');
        $this->line('  ╚══════════════════════════════════════╝');
        $this->newLine();
        $this->line('  Langkah selanjutnya:');
        $this->line('  1. npm install');
        $this->line('  2. npm run build');
        $this->line('  3. php artisan zycrypt:check           (verifikasi lisensi)');
        $this->line('  4. php artisan zycrypt:guard status    (cek status DB guard)');
        $this->newLine();
    }
}
