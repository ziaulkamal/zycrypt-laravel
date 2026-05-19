<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lisensi Tidak Valid — {{ $product_name ?? config('app.name') }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f8fafc;
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 2.5rem;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06);
            text-align: center;
        }

        .icon-wrap {
            width: 64px;
            height: 64px;
            background: #fef2f2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }

        .icon-wrap svg {
            width: 32px;
            height: 32px;
            color: #dc2626;
        }

        h1 {
            font-size: 1.375rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0.5rem;
        }

        .subtitle {
            color: #64748b;
            font-size: 0.9375rem;
            line-height: 1.6;
            margin-bottom: 1.75rem;
        }

        .meta {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.75rem;
            text-align: left;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.375rem 0;
            font-size: 0.875rem;
        }

        .meta-row:not(:last-child) {
            border-bottom: 1px solid #e2e8f0;
        }

        .meta-label { color: #64748b; }
        .meta-value { color: #0f172a; font-weight: 500; font-family: monospace; }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.625rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #fef2f2;
            color: #dc2626;
        }

        .contact {
            color: #64748b;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }

        .contact a {
            color: #1a56db;
            text-decoration: none;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #374151;
            transition: background 0.15s;
            text-decoration: none;
        }

        .btn:hover { background: #f8fafc; }

        .footer {
            margin-top: 2rem;
            padding-top: 1.25rem;
            border-top: 1px solid #f1f5f9;
            font-size: 0.75rem;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
            </svg>
        </div>

        <h1>Akses Ditangguhkan</h1>
        <p class="subtitle">
            Lisensi aplikasi <strong>{{ $product_name ?? config('app.name') }}</strong>
            tidak valid atau telah berakhir.
        </p>

        <div class="meta">
            <div class="meta-row">
                <span class="meta-label">Produk</span>
                <span class="meta-value">{{ $product_name ?? config('app.name') }}</span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Status</span>
                <span class="badge">{{ $reason ?? 'license_invalid' }}</span>
            </div>
            @if(!empty($detail))
            <div class="meta-row">
                <span class="meta-label">Keterangan</span>
                <span class="meta-value" style="font-family:inherit;font-size:0.8125rem;">{{ $detail }}</span>
            </div>
            @endif
        </div>

        @if(!empty($contact_email))
        <p class="contact">
            Hubungi administrator di
            <a href="mailto:{{ $contact_email }}">{{ $contact_email }}</a>
            untuk informasi lebih lanjut.
        </p>
        @endif

        <a href="javascript:location.reload()" class="btn">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none"
                viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            Coba Lagi
        </a>

        <div class="footer">
            Dilindungi oleh ZyCrypt License Manager
        </div>
    </div>
</body>
</html>
