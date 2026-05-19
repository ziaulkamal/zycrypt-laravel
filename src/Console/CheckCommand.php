<?php

namespace ZyCrypt\Laravel\Console;

use Illuminate\Console\Command;
use ZyCrypt\Laravel\Services\LicenseValidator;

class CheckCommand extends Command
{
    protected $signature   = 'zycrypt:check';
    protected $description = 'Periksa status lisensi ZyCrypt saat ini';

    public function handle(LicenseValidator $validator): int
    {
        $this->newLine();
        $this->line('  Memeriksa status lisensi...');
        $this->newLine();

        // Cek lock file lokal
        $lockPath = config('zycrypt.lock_path');
        if (file_exists($lockPath)) {
            $lock = $validator->readLock();
            if ($lock) {
                $age = now()->diffInHours(\Carbon\Carbon::parse($lock['validated_at']));
                $this->line('  Lock file  : Ada');
                $this->line('  Validasi   : ' . $lock['validated_at'] . ' (' . $age . ' jam lalu)');
                $this->line('  Plan       : ' . ($lock['plan'] ?? '-'));
                $this->line('  Expired    : ' . ($lock['is_lifetime'] ? 'Lifetime' : ($lock['expires_at'] ?? '-')));
                $this->newLine();
            }
        } else {
            $this->warn('  Lock file tidak ditemukan. Belum pernah diinstall.');
            $this->newLine();
        }

        // Re-validate ke server
        $this->line('  Validasi ke server...');
        $result = $validator->validate();

        if ($result['valid']) {
            $data = $result['data'];
            $this->info('  ✓ Lisensi VALID');
            $this->line('    Plan     : ' . ($data['plan'] ?? '-'));
            $this->line('    Expired  : ' . ($data['is_lifetime'] ? 'Lifetime' : ($data['expires_at'] ?? '-')));
            $this->line('    Limit    : ' . ($data['site_limit'] ?? '-') . ' domain');
        } else {
            $this->error('  ✗ Lisensi TIDAK VALID');
            $this->line('    Alasan : ' . ($result['reason'] ?? 'unknown'));
            if (! empty($result['detail'])) {
                $this->line('    Detail : ' . $result['detail']);
            }
        }

        $this->newLine();
        return $result['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
