<?php

namespace ZyCrypt\Laravel;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use ZyCrypt\Laravel\Services\LicenseValidator;
use ZyCrypt\Laravel\Services\DatabaseGuard;
use ZyCrypt\Laravel\Console\InstallCommand;
use ZyCrypt\Laravel\Console\CheckCommand;
use ZyCrypt\Laravel\Console\DbGuardCommand;

class ZyCryptServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/zycrypt.php', 'zycrypt');

        $this->app->singleton(LicenseValidator::class, function ($app) {
            return new LicenseValidator(
                serverUrl:    config('zycrypt.server_url'),
                licenseKey:   config('zycrypt.license_key'),
                sharedSecret: config('zycrypt.shared_secret'),
                lockPath:     config('zycrypt.lock_path'),
                graceHours:   config('zycrypt.grace_hours'),
            );
        });

        $this->app->singleton(DatabaseGuard::class, function ($app) {
            return new DatabaseGuard();
        });
    }

    public function boot(): void
    {
        $this->publishAssets();
        $this->registerRoutes();
        $this->registerCommands();
        $this->checkLicense();
    }

    private function publishAssets(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/zycrypt.php' => config_path('zycrypt.php'),
        ], 'zycrypt-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/zycrypt'),
        ], 'zycrypt-views');
    }

    private function registerRoutes(): void
    {
        Route::prefix('zycrypt')
            ->middleware('web')
            ->group(function () {
                Route::post('/token', [Http\Controllers\ZyCryptProxyController::class, 'token'])
                    ->name('zycrypt.token');
            });
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                CheckCommand::class,
                DbGuardCommand::class,
            ]);
        }
    }

    private function checkLicense(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $validator = $this->app->make(LicenseValidator::class);
        $result    = $validator->checkLock();

        if (! $result['valid']) {
            $this->app->booted(function () use ($result) {
                $this->forceErrorPage($result['reason'] ?? 'license_invalid', $result['detail'] ?? '');
            });
            return;
        }

        $this->app->booted(function () use ($validator) {
            $guard = $this->app->make(DatabaseGuard::class);
            $lock  = $validator->readLock() ?? [];

            $guardWasInstalled = ! empty($lock['guard_installed']);
            $guardIsInstalled  = $guard->isInstalled();

            if ($guardWasInstalled && ! $guardIsInstalled) {
                try {
                    $guard->install();
                    $guardIsInstalled = true;
                    $validator->markGuardInstalled();
                } catch (\Throwable) {
                    $this->forceErrorPage('db_guard_missing', 'Proteksi database tidak aktif.');
                    return;
                }
            }

            if ($guardIsInstalled) {
                $token = bin2hex(random_bytes(32));
                try {
                    $guard->activateSession($token);
                } catch (\Throwable) {
                    $this->forceErrorPage('db_guard_failure', 'Tidak dapat mengaktifkan sesi database.');
                }
            }
        });
    }

    private function forceErrorPage(string $reason, string $detail): void
    {
        $excluded = config('zycrypt.excluded_routes', []);

        $this->app['router']->matched(function ($event) use ($reason, $detail, $excluded) {
            $uri = $event->request->path();

            foreach ($excluded as $pattern) {
                if (\Illuminate\Support\Str::is($pattern, $uri)) {
                    return;
                }
            }

            abort(response()->view('vendor.zycrypt.license-invalid', [
                'reason'        => $reason,
                'detail'        => $detail,
                'product_name'  => config('zycrypt.product_name'),
                'contact_email' => config('zycrypt.contact_email'),
            ], 403));
        });
    }
}
