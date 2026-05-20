<?php

namespace ZyCrypt\Laravel\Services;

use Illuminate\Support\Facades\Http;

class LicenseValidator
{
    public function __construct(
        private readonly string  $serverUrl,
        private readonly ?string $licenseKey,
        private readonly ?string $sharedSecret,
        private readonly string  $lockPath,
        private readonly int     $graceHours,
    ) {}

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

        if ($age < 1) {
            return ['valid' => true, 'data' => $lock];
        }

        if ($age < $this->graceHours) {
            $result = $this->validate();
            if ($result['valid']) {
                $this->writeLock($result['data']);
                return ['valid' => true, 'data' => $result['data']];
            }
            if ($result['reason'] === 'server_unreachable') {
                return ['valid' => true, 'data' => $lock, 'grace' => true];
            }
            return $result;
        }

        return $this->tryRevalidate('grace_expired');
    }

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

    public function verifyToken(string $token): bool
    {
        if (! $this->sharedSecret) {
            return false;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$payloadB64, $sig] = $parts;

        $expected = hash_hmac('sha256', 'session-v1:' . $payloadB64, $this->sharedSecret);
        if (! hash_equals($expected, $sig)) {
            return false;
        }

        $data = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
        return isset($data['exp']) && $data['exp'] > time();
    }

    public function writeLock(array $data): void
    {
        $lockDir = dirname($this->lockPath);
        if (! is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        $existing = file_exists($this->lockPath) ? ($this->readLock() ?? []) : [];

        $content = json_encode([
            'validated_at'  => now()->toIso8601String(),
            'license_key'   => $this->licenseKey,
            'plan'          => $data['plan'] ?? null,
            'expires_at'    => $data['expires_at'] ?? null,
            'is_lifetime'   => $data['is_lifetime'] ?? false,
            'site_limit'    => $data['site_limit'] ?? 1,
            'session_token' => $data['session_token'] ?? null,
            'guard_installed' => $data['guard_installed'] ?? ($existing['guard_installed'] ?? false),
        ]);

        file_put_contents($this->lockPath, base64_encode($content));
    }

    public function markGuardInstalled(): void
    {
        $lock = $this->readLock();
        if (! $lock) {
            return;
        }
        $lock['guard_installed'] = true;
        file_put_contents($this->lockPath, base64_encode(json_encode($lock)));
    }

    public function readLock(): ?array
    {
        $raw = file_get_contents($this->lockPath);
        if (! $raw) {
            return null;
        }

        $decoded = base64_decode($raw, strict: true);
        if (! $decoded) {
            return null;
        }

        return json_decode($decoded, true);
    }

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

    private function decryptPayload(string $base64): array
    {
        $raw    = base64_decode($base64);
        $key    = substr(hash_hmac('sha256', 'aes-key-derivation', $this->sharedSecret), 0, 32);
        $nonce  = substr($raw, 0, 12);
        $body   = substr($raw, 12);
        $tag    = substr($body, -16);
        $cipher = substr($body, 0, -16);
        $plain  = openssl_decrypt($cipher, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        return json_decode($plain ?: '{}', true);
    }
}
