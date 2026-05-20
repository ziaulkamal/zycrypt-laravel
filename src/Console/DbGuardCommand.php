<?php

namespace ZyCrypt\Laravel\Console;

use Illuminate\Console\Command;
use ZyCrypt\Laravel\Services\DatabaseGuard;
use ZyCrypt\Laravel\Services\LicenseValidator;

class DbGuardCommand extends Command
{
    protected $signature = 'zycrypt:guard
        {action : install | remove | status}
        {--force : Lewati konfirmasi saat remove}';

    protected $description = 'Kelola database-level guard (triggers/token table) untuk ZyCrypt';

    public function handle(DatabaseGuard $guard, LicenseValidator $validator): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'install' => $this->runInstall($guard, $validator),
            'remove'  => $this->runRemove($guard, $validator),
            'status'  => $this->runStatus($guard),
            default   => $this->badAction($action),
        };
    }

    private function runInstall(DatabaseGuard $guard, LicenseValidator $validator): int
    {
        $this->newLine();
        $this->line('  ╔══════════════════════════════════════╗');
        $this->line('  ║    ZyCrypt — Database Guard Install  ║');
        $this->line('  ╚══════════════════════════════════════╝');
        $this->newLine();

        $this->info('  Mendeteksi driver database...');
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        $this->line("  ✓ Driver : {$driver}");

        if ($driver === 'sqlite') {
            $this->warn('  ⚠ SQLite tidak didukung untuk database guard.');
            $this->line('  Database guard hanya bekerja pada MySQL dan PostgreSQL.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('  Memasang token table dan triggers...');

        try {
            $guard->install();
        } catch (\Throwable $e) {
            $this->error('  ✗ Gagal: ' . $e->getMessage());
            return self::FAILURE;
        }

        $tables = $guard->applicationTables();

        $this->line('  ✓ Tabel zycrypt_tokens dibuat');
        $this->line('  ✓ Triggers dipasang pada ' . count($tables) . ' tabel:');
        foreach ($tables as $t) {
            $this->line("      · {$t}");
        }

        $this->newLine();
        $this->line('  ╔══════════════════════════════════════╗');
        $this->line('  ║  ✓  Database guard aktif!            ║');
        $this->line('  ╚══════════════════════════════════════╝');
        $this->newLine();
        $this->line('  Penting: Guard ini TETAP aktif meskipun package ZyCrypt dihapus.');
        $this->line('  Untuk menonaktifkan, jalankan: php artisan zycrypt:guard remove');
        $this->newLine();

        $validator->markGuardInstalled();

        return self::SUCCESS;
    }

    private function runRemove(DatabaseGuard $guard, LicenseValidator $validator): int
    {
        $this->newLine();

        if (! $this->option('force')) {
            $this->warn('  ⚠  Peringatan: Ini akan menghapus SEMUA trigger ZyCrypt dari database.');
            $this->line('  Setelah dihapus, aplikasi akan berjalan TANPA proteksi database.');
            $this->newLine();

            if (! $this->confirm('  Lanjutkan penghapusan database guard?', false)) {
                $this->line('  Dibatalkan.');
                return self::SUCCESS;
            }
        }

        $this->info('  Menghapus database guard...');

        try {
            $guard->remove();
        } catch (\Throwable $e) {
            $this->error('  ✗ Gagal: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->line('  ✓ Semua trigger ZyCrypt dihapus');
        $this->line('  ✓ Tabel zycrypt_tokens dihapus');
        $this->newLine();

        return self::SUCCESS;
    }

    private function runStatus(DatabaseGuard $guard): int
    {
        $this->newLine();
        $this->line('  ZyCrypt — Database Guard Status');
        $this->line('  ' . str_repeat('─', 38));
        $this->newLine();

        $driver    = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        $installed = $guard->isInstalled();

        $this->line("  Driver     : {$driver}");
        $this->line('  Guard      : ' . ($installed ? '<fg=green>Aktif</>' : '<fg=red>Tidak aktif</>'));

        if ($installed) {
            $tables = $guard->applicationTables();
            $this->line('  Tabel      : ' . count($tables) . ' tabel diproteksi');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function badAction(string $action): int
    {
        $this->error("Aksi tidak dikenal: {$action}");
        $this->line('  Gunakan: install | remove | status');
        return self::FAILURE;
    }
}
