<?php

namespace ZyCrypt\Laravel\Services;

use Illuminate\Support\Facades\Http;

class BundleInstaller
{
    private string $projectRoot;

    public function __construct(
        private readonly string  $serverUrl,
        private readonly ?string $licenseKey,
        private readonly ?string $sharedSecret,
    ) {
        $this->projectRoot = base_path();
    }

    public function availableThemes(): array
    {
        $domain    = parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost';
        $timestamp = now()->timestamp;
        $sig       = hash_hmac('sha256', $this->licenseKey . ':' . $domain . ':' . $timestamp, $this->sharedSecret);

        try {
            $response = Http::timeout(10)->post($this->serverUrl . '/api/v1/themes', [
                'license_key' => $this->licenseKey,
                'domain'      => $domain,
                'timestamp'   => $timestamp,
                'signature'   => $sig,
            ]);

            if ($response->successful()) {
                return $response->json('themes') ?? [];
            }
        } catch (\Throwable) {}

        return [];
    }

    public function install(string $themeSlug, callable $log): bool
    {
        $log('Meminta delivery token dari server...');

        $activation = $this->activate($themeSlug);
        if (! $activation) {
            $log('✗ Gagal mendapatkan delivery token.');
            return false;
        }

        $log('✓ Tema tersedia: ' . ($activation['theme_name'] ?? $themeSlug) . ' v' . ($activation['theme_version'] ?? '?'));
        $log('Mengunduh bundle tema...');

        $bundle = $this->download($activation['delivery_token']);
        if (! $bundle) {
            $log('✗ Gagal mengunduh bundle.');
            return false;
        }

        $log('Memverifikasi checksum...');
        $decrypted = $this->decryptBundle($bundle['data']);
        if (! $decrypted) {
            $log('✗ Dekripsi bundle gagal.');
            return false;
        }

        $actualChecksum = hash('sha256', $decrypted);
        if ($actualChecksum !== $bundle['checksum']) {
            $log('✗ Checksum tidak cocok. Bundle mungkin rusak atau dimanipulasi.');
            return false;
        }

        $log('Mengekstrak file ke project...');
        $this->extract($decrypted, $log);

        return true;
    }

    private function activate(string $themeSlug): ?array
    {
        $domain    = parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost';
        $timestamp = now()->timestamp;
        $sig       = hash_hmac('sha256', $this->licenseKey . ':' . $domain . ':' . $themeSlug . ':' . $timestamp, $this->sharedSecret);

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

    private function download(string $deliveryToken): ?array
    {
        try {
            $response = Http::timeout(120)->get(
                $this->serverUrl . '/api/v1/download/' . $deliveryToken
            );

            if (! $response->successful()) {
                return null;
            }

            $data     = $response->json('data');
            $checksum = $response->json('checksum');

            if (! $data || ! $checksum) {
                return null;
            }

            return ['data' => $data, 'checksum' => $checksum];
        } catch (\Throwable) {
            return null;
        }
    }

    private function decryptBundle(string $base64Data): ?string
    {
        $key    = substr(hash_hmac('sha256', 'bundle-key', $this->sharedSecret), 0, 32);
        $raw    = base64_decode($base64Data);
        $iv     = substr($raw, 0, 16);
        $data   = substr($raw, 16);
        $result = openssl_decrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        return $result ?: null;
    }

    private function extract(string $zipContent, callable $log): void
    {
        $tmpZip = sys_get_temp_dir() . '/zycrypt-bundle-' . uniqid() . '.zip';
        file_put_contents($tmpZip, $zipContent);

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            $log('✗ Gagal membuka ZIP bundle.');
            return;
        }

        $map = $this->pathMap();

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = str_replace('\\', '/', $zip->getNameIndex($i));

            if (str_ends_with($entry, '/')) {
                continue;
            }

            $dest = $this->resolveDest($entry, $map);

            if (! $dest) continue;

            $dest = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dest);
            $dir  = dirname($dest);

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($dest, $zip->getFromIndex($i));
            $log('  ✓ ' . $entry);
        }

        $zip->close();
        unlink($tmpZip);
    }

    private function pathMap(): array
    {
        $root = $this->projectRoot;
        $js   = $root . '/resources/js';
        $css  = $root . '/resources/css';
        $views = $root . '/resources/views';

        return [
            'components/'  => $js    . '/Components/',
            'pages/'       => $js    . '/Pages/',
            'layouts/'     => $js    . '/Layouts/',
            'composables/' => $js    . '/Composables/',
            'config/'      => $js    . '/config/',
            'data/'        => $js    . '/data/',
            'css/'         => $css   . '/',
            'views/'       => $views . '/',
            'app/'         => $js    . '/',
        ];
    }

    private function resolveDest(string $entry, array $map): ?string
    {
        foreach ($map as $prefix => $targetDir) {
            if (str_starts_with($entry, $prefix)) {
                $relative = substr($entry, strlen($prefix));
                if ($relative === '') continue;
                return $targetDir . $relative;
            }
        }
        return null;
    }
}
