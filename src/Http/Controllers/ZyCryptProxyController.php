<?php

namespace ZyCrypt\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ZyCrypt\Laravel\Services\LicenseValidator;

class ZyCryptProxyController extends Controller
{
    public function __construct(private readonly LicenseValidator $validator) {}

    public function token(Request $request): JsonResponse
    {
        $result = $this->validator->validate();

        if (! $result['valid']) {
            return response()->json([
                'valid'  => false,
                'reason' => $result['reason'],
                'detail' => $result['detail'] ?? '',
            ], 403);
        }

        $data = $result['data'];

        $this->validator->writeLock($data);

        $token = $data['session_token'] ?? null;

        if (! $token) {
            return response()->json(['valid' => false, 'reason' => 'token_missing'], 500);
        }

        return response()->json([
            'valid'       => true,
            'token'       => $token,
            'expires_in'  => config('zycrypt.token_ttl_minutes', 10) * 60,
            'plan'        => $data['plan'] ?? null,
            'is_lifetime' => $data['is_lifetime'] ?? false,
            'expires_at'  => $data['expires_at'] ?? null,
        ]);
    }
}
