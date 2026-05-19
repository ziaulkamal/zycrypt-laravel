<?php

namespace ZyCrypt\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ZyCrypt\Laravel\Services\LicenseValidator;

class VerifyZyCryptToken
{
    public function __construct(private readonly LicenseValidator $validator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-ZyCrypt-Token');

        if (! $token || ! $this->validator->verifyToken($token)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'valid'  => false,
                    'reason' => 'token_expired',
                    'detail' => 'ZyCrypt token tidak valid atau sudah expired. Silakan re-validate.',
                ], 403);
            }

            return response()->view('vendor.zycrypt.license-invalid', [
                'reason'        => 'token_expired',
                'detail'        => 'Sesi lisensi Anda telah berakhir.',
                'product_name'  => config('zycrypt.product_name'),
                'contact_email' => config('zycrypt.contact_email'),
            ], 403);
        }

        return $next($request);
    }
}
