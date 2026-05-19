<?php

namespace ZyCrypt\Laravel\Services;

use Illuminate\Support\Facades\Http;

class LicenseValidator
{
    public function __construct(
        private readonly string $serverUrl,
        private readonly string $licenseKey,
        private readonly string $sharedSecret,
        private readonly string $lockPath,
        private readonly int    $graceHours,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Cek lock file. Jika expired, coba re-validate ke server.
     */
    public function checkLock(): array
    {
        if (! file_exists($this->lockPath)) {
            return $this->tryRevalidate('lock_missing');
        }

        $lock = $this->readLock();
        if (! $lock) {
            return $this->tryRevalidate('lock_corrupt');
        }

        $age = now()->diffInHours(\Carbon\Carbon::parse($lock['validated_at']));

        // Lock masih segar (< 1 jam) — langsung lolos
        if ($age < 1) {
            return ['valid' => true, 'data' => $lock];
        }

        // Dalam grace period — coba re-validate, tapi jika gagal masih izinkan
        if ($age < $this->graceHours) {
            $result = $this->validate();
            if ($result['valid']) {
                $this->writeLock($result['data']);
                return ['valid' => true, 'data' => $result['data']];
            }
            // Server unreachable — masih dalam grace period
            if ($result['reason'] === 'server_unreachable') {
                return ['valid' => true, 'data' => $lock, 'grace' => true];
            }
            // Server menjawab invalid (ban, expired) — tolak meskipun dalam grace
            return $result;
        }

        // Grace period habis
        return $this->tryRevalidate('grace_expired');
    }

    /**
     * Validate langsung ke ZyCrypt server.
     */
    public function validate(): array
    {
        if (! $this->licenseKey || ! $this->sharedSecret) {
            return [
                'valid'  => false,
                'reason' => 'config_missing',
                'detail' => 'ZYCRYPT_LICENSE_KEY atau ZYCRYPT_SHARED_SECRET belum diset di .env',
            ];
        }

        $domain    = parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost';
        $timestamp = now()->timestamp;
        $signature = $this->sign($this->licenseKey . ':' . $domain . ':' . $timestamp);

        try {
            $response = Http::timeout(10)->post($this->serverUrl . '/api/v1/validate', [
                'license_key' => $this->licenseKey,
                'domain'      => $domain,
                'pkg_version' => '1.0.0',
                'timestamp'   => $timestamp,
                'signature'   => $signature,
            ]);

            if ($response->successful()) {
                $payload = $this->decryptPayload($response->json('data'));
                return ['valid' => true, 'data' => $payload];
            }

            $body = $response->json();
            return [
                'valid'  => false,
                'reason' => $body['reason'] ?? 'unknown',
                'detail' => $body['detail'] ?? '',
            ];

        } catch (\Throwable $e) {
            return [
                'valid'  => false,
                'reason' => 'server_unreachable',
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate token singkat untuk dikirim ke Vue frontend.
     * Token ini yang diverifikasi middleware per-request.
     */
    public function generateToken(): string
    {
        $payload = json_encode([
            'key' => $this->licenseKey,
            'ts'  => now()->timestamp,
            'exp' => now()->addMinutes(config('zycrypt.token_ttl_minutes', 10))->timestamp,
        ]);

        $iv         = random_bytes(16);
        $key        = $this->deriveKey();
        $ciphertext = openssl_encrypt($payload, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . $ciphertext);
    }

    /**
     * Verifikasi token yang datang dari header X-ZyCrypt-Token.
     */
    public function verifyToken(string $token): bool
    {
        try {
            $raw  = base64_decode($token, strict: true);
            if (! $raw) return false;

            $iv         = substr($raw, 0, 16);
            $ciphertext = substr($raw, 16);
            $key        = $this->deriveKey();

            $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if (! $plaintext) return false;

            $data = json_decode($plaintext, true);
            if (! isset($data['exp'])) return false;

            return $data['exp'] > now()->timestamp;
        } catch (\Throwable) {
            return false;
        }
    }

    public function writeLock(array $data): void
    {
        $lockDir = dirname($this->lockPath);
        if (! is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        $content = json_encode([
            'validated_at' => now()->toIso8601String(),
            'license_key'  => $this->licenseKey,
            'plan'         => $data['plan'] ?? null,
            'expires_at'   => $data['expires_at'] ?? null,
            'is_lifetime'  => $data['is_lifetime'] ?? false,
            'site_limit'   => $data['site_limit'] ?? 1,
        ]);

        file_put_contents($this->lockPath, base64_encode($content));
    }

    public function readLock(): ?array
    {
        $raw = file_get_contents($this->lockPath);
        if (! $raw) return null;

        $decoded = base64_decode($raw, strict: true);
        if (! $decoded) return null;

        return json_decode($decoded, true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function tryRevalidate(string $context): array
    {
        $result = $this->validate();
        if ($result['valid']) {
            $this->writeLock($result['data']);
        }
        return $result;
    }

    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->sharedSecret);
    }

    private function deriveKey(): string
    {
        return substr(hash_hmac('sha256', 'token-key-derivation', $this->sharedSecret, true), 0, 32);
    }

    private function decryptPayload(string $base64): array
    {
        $raw        = base64_decode($base64);
        $key        = substr(hash_hmac('sha256', 'aes-key-derivation', $this->sharedSecret, true), 0, 32);
        $nonce      = substr($raw, 0, 12);
        $ciphertext = substr($raw, 12);

        // AES-256-GCM (sesuai implementasi Go)
        $tag      = substr($ciphertext, -16);
        $cipher   = substr($ciphertext, 0, -16);
        $plain    = openssl_decrypt($cipher, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        return json_decode($plain ?: '{}', true);
    }
}
