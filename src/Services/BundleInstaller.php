<?php

namespace ZyCrypt\Laravel\Services;

use Illuminate\Support\Facades\Http;

class BundleInstaller
{
    private string $projectRoot;

    public function __construct(
        private readonly string $serverUrl,
        private readonly string $licenseKey,
        private readonly string $sharedSecret,
    ) {
        $this->projectRoot = base_path();
    }

    /**
     * Minta delivery token dari server lalu download dan ekstrak bundle.
     */
    public function install(string $themeSlug, callable $log): bool
    {
        $log('Meminta delivery token dari server...');

        $token = $this->activate($themeSlug);
        if (! $token) {
            $log('✗ Gagal mendapatkan delivery token.');
            return false;
        }

        $log('Mengunduh bundle tema...');
        $bundle = $this->download($token['delivery_token']);
        if (! $bundle) {
            $log('✗ Gagal mengunduh bundle.');
            return false;
        }

        $log('Memverifikasi checksum...');
        $actualChecksum = hash('sha256', $bundle);
        if ($actualChecksum !== $token['checksum']) {
            $log('✗ Checksum tidak cocok. Bundle mungkin rusak atau dimanipulasi.');
            return false;
        }

        $log('Mendekripsi bundle...');
        $decrypted = $this->decryptBundle($bundle);
        if (! $decrypted) {
            $log('✗ Dekripsi bundle gagal.');
            return false;
        }

        $log('Mengekstrak file ke project...');
        $this->extract($decrypted, $log);

        return true;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function activate(string $themeSlug): ?array
    {
        $domain    = parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost';
        $timestamp = now()->timestamp;
        $sig       = hash_hmac('sha256', $this->licenseKey . ':' . $domain . ':' . $timestamp, $this->sharedSecret);

        try {
            $response = Http::timeout(30)->post($this->serverUrl . '/api/v1/activate', [
                'license_key' => $this->licenseKey,
                'domain'      => $domain,
                'theme_slug'  => $themeSlug,
                'timestamp'   => $timestamp,
                'signature'   => $sig,
            ]);

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function download(string $deliveryToken): ?string
    {
        try {
            $response = Http::timeout(120)->get(
                $this->serverUrl . '/api/v1/download/' . $deliveryToken
            );

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function decryptBundle(string $bundle): ?string
    {
        $key  = substr(hash_hmac('sha256', 'bundle-key', $this->sharedSecret, true), 0, 32);
        $raw  = base64_decode($bundle);
        $iv   = substr($raw, 0, 16);
        $data = substr($raw, 16);

        $result = openssl_decrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $result ?: null;
    }

    private function extract(string $zipContent, callable $log): void
    {
        // Tulis zip sementara
        $tmpZip = sys_get_temp_dir() . '/zycrypt-bundle-' . uniqid() . '.zip';
        file_put_contents($tmpZip, $zipContent);

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            $log('✗ Gagal membuka ZIP bundle.');
            return;
        }

        $map = $this->pathMap();

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            $dest  = $this->resolveDest($entry, $map);

            if (! $dest) continue;

            // Buat direktori tujuan
            $dir = dirname($dest);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Backup jika file sudah ada
            if (file_exists($dest)) {
                rename($dest, $dest . '.bak.' . date('Ymd_His'));
            }

            file_put_contents($dest, $zip->getFromIndex($i));
            $log('  ✓ ' . $entry);
        }

        $zip->close();
        unlink($tmpZip);
    }

    /**
     * Peta prefix ZIP → direktori tujuan di project.
     */
    private function pathMap(): array
    {
        $js  = $this->projectRoot . '/resources/js';
        $css = $this->projectRoot . '/resources/css';

        return [
            'components/' => $js . '/Components/',
            'pages/'      => $js . '/Pages/',
            'layouts/'    => $js . '/Layouts/',
            'composables/'=> $js . '/Composables/',
            'config/'     => $js . '/config/',
            'data/'       => $js . '/data/',
            'css/'        => $css . '/',
        ];
    }

    private function resolveDest(string $entry, array $map): ?string
    {
        foreach ($map as $prefix => $targetDir) {
            if (str_starts_with($entry, $prefix)) {
                $relative = substr($entry, strlen($prefix));
                if ($relative === '') continue; // skip direktori kosong
                return $targetDir . $relative;
            }
        }
        return null;
    }
}
