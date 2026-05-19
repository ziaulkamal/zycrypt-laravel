<?php

namespace ZyCrypt\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ZyCrypt\Laravel\Services\LicenseValidator;

/**
 * Proxy endpoint yang dipanggil Vue (frontend) untuk mendapatkan token.
 * Shared secret TIDAK pernah dikirim ke browser — signing dilakukan di sini.
 */
class ZyCryptProxyController extends Controller
{
    public function __construct(private readonly LicenseValidator $validator) {}

    /**
     * POST /zycrypt/token
     * Dipanggil oleh zycrypt-vue saat boot dan setiap 10 menit.
     */
    public function token(Request $request): JsonResponse
    {
        // Validasi ke server ZyCrypt (server-to-server, secret aman)
        $result = $this->validator->validate();

        if (! $result['valid']) {
            return response()->json([
                'valid'  => false,
                'reason' => $result['reason'],
                'detail' => $result['detail'] ?? '',
            ], 403);
        }

        // Perbarui lock file
        $this->validator->writeLock($result['data']);

        // Generate token singkat untuk Vue
        $token = $this->validator->generateToken();

        return response()->json([
            'valid'      => true,
            'token'      => $token,
            'expires_in' => config('zycrypt.token_ttl_minutes', 10) * 60, // dalam detik
            'plan'       => $result['data']['plan'] ?? null,
            'is_lifetime'=> $result['data']['is_lifetime'] ?? false,
            'expires_at' => $result['data']['expires_at'] ?? null,
        ]);
    }
}
