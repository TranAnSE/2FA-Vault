<?php

namespace App\Providers;

use App\Facades\Settings;
use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Console\ClientCommand;
use Laravel\Passport\Console\InstallCommand;
use Laravel\Passport\Console\KeysCommand;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        URL::forceRootUrl(config('app.url'));

        // Limited to 191 to prevent index length issue with MyISAM and utf8mb4_unicode_ci
        // when using WAMP (WAMP uses MyISAM as default engine in place of INNOdb)
        Schema::defaultStringLength(191);

        JsonResource::withoutWrapping();

        // Passport v13 defaults to UUID client IDs. The fork's oauth_clients
        // schema uses integer IDs, so we opt out to keep backward compatibility.
        Passport::$clientUuids = false;
        // Passport v13 validates RSA key file permissions on Unix; the Windows
        // dev environment does not use the same permission model, so disable
        // the check to avoid false positives.
        Passport::$validateKeyPermissions = false;

        $this->commands([
            InstallCommand::class,
            ClientCommand::class,
            KeysCommand::class,
        ]);

        Gate::before(function (User $user, string $ability) {
            if ($user->isAdministrator()) {
                return true;
            }
        });

        Gate::define('manage-pat', function (User $user) {
            $useSsoOnly = Settings::get('useSsoOnly');

            return ($useSsoOnly && Settings::get('allowPatWhileSsoOnly')) || $useSsoOnly !== true;
        });

        Gate::define('manage-webauthn-credentials', function (User $user) {
            return ! Settings::get('useSsoOnly');
        });

        $this->registerWebDavDriver();
    }

    /**
     * Register the WebDAV Flysystem v3 driver for auto-backup destinations.
     * The adapter package (league/flysystem-webdav) must be installed via composer.
     *
     * PHP 8.4 note: sabre/dav 4.x (a transitive dependency) emits
     * E_DEPRECATED warnings about implicitly nullable parameter types from its
     * server-side classes (CalDAV/CardDAV/DAVACL/Server tree nodes). This
     * project only uses \Sabre\DAV\Client (the WebDAV *client*), so the
     * deprecated code paths are never loaded and the warnings do not fire in
     * practice. Bumping sabre/dav to 5.x would clear them upstream but is
     * currently blocked by league/flysystem-webdav's ^4.6.0 constraint.
     */
    private function registerWebDavDriver() : void
    {
        Storage::extend('webdav', function ($app, array $config) {
            $client = new \Sabre\DAV\Client([
                'baseUri'  => rtrim($config['baseUri'], '/') . '/',
                'userName' => $config['userName'] ?? '',
                'password' => $config['password'] ?? '',
            ]);

            $adapter = new \League\Flysystem\WebDAV\WebDAVAdapter(
                $client,
                $config['pathPrefix'] ?? ''
            );

            return new \League\Flysystem\Filesystem($adapter);
        });
    }
}
