<?php

return [

    /*
    |--------------------------------------------------------------------------
    | License Key
    |--------------------------------------------------------------------------
    | Kunci lisensi yang didapat dari vendor ZyCrypt.
    | Format: ZYC-XXXX-XXXX-XXXX-XXXX
    */
    'license_key' => env('ZYCRYPT_LICENSE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Server URL
    |--------------------------------------------------------------------------
    | URL ZyCrypt license server milik vendor.
    */
    'server_url' => env('ZYCRYPT_SERVER_URL', 'https://zycrypt.yourdomain.com'),

    /*
    |--------------------------------------------------------------------------
    | Shared Secret
    |--------------------------------------------------------------------------
    | Kunci HMAC rahasia — harus sama dengan yang ada di zycrypt.yaml server.
    | Jangan pernah expose ke frontend atau commit ke repository.
    */
    'shared_secret' => env('ZYCRYPT_SHARED_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Grace Period
    |--------------------------------------------------------------------------
    | Berapa jam aplikasi boleh berjalan jika server ZyCrypt tidak terjangkau.
    | Default: 24 jam. Set 0 untuk menonaktifkan grace period.
    */
    'grace_hours' => env('ZYCRYPT_GRACE_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Lock File Path
    |--------------------------------------------------------------------------
    | Lokasi file cache validasi lisensi (.zycrypt.lock).
    */
    'lock_path' => storage_path('app/.zycrypt.lock'),

    /*
    |--------------------------------------------------------------------------
    | Token TTL
    |--------------------------------------------------------------------------
    | Berapa menit token yang dikirim ke frontend berlaku.
    | Harus sama dengan token_ttl_minutes di zycrypt.yaml server.
    */
    'token_ttl_minutes' => env('ZYCRYPT_TOKEN_TTL', 10),

    /*
    |--------------------------------------------------------------------------
    | Product Name
    |--------------------------------------------------------------------------
    | Nama produk yang ditampilkan di halaman error lisensi.
    */
    'product_name' => env('ZYCRYPT_PRODUCT_NAME', config('app.name')),

    /*
    |--------------------------------------------------------------------------
    | Contact Email
    |--------------------------------------------------------------------------
    | Email kontak yang tampil di halaman error lisensi.
    */
    'contact_email' => env('ZYCRYPT_CONTACT_EMAIL', ''),

    /*
    |--------------------------------------------------------------------------
    | Excluded Routes
    |--------------------------------------------------------------------------
    | URI yang dikecualikan dari pengecekan middleware ZyCrypt.
    | Selalu sertakan route internal ZyCrypt agar tidak loop.
    */
    'excluded_routes' => [
        'zycrypt/*',
        '_debugbar/*',
        'horizon/*',
        'telescope/*',
    ],

    'npm_package_path' => env('ZYCRYPT_NPM_PATH', 'zycrypt-vue'),

];
