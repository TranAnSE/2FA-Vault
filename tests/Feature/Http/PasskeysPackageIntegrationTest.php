<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * Phase 2 invariants of the laravel/passkeys migration
 * (plan 261009-passkeys-migration, D3/D4, red-team H3).
 *
 * @coversNothing
 */
class PasskeysPackageIntegrationTest extends FeatureTestCase
{
    #[Test]
    public function package_routes_must_not_be_registered(): void
    {
        $passkeyRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'passkeys'));

        $this->assertSame([], $passkeyRoutes->pluck('uri')->all());
    }

    #[Test]
    public function relying_party_id_resolves_from_app_url_host(): void
    {
        $this->assertSame(
            parse_url(config('app.url'), PHP_URL_HOST),
            config('passkeys.relying_party_id')
        );
    }

    #[Test]
    public function user_model_is_a_valid_passkey_user(): void
    {
        $this->assertInstanceOf(
            \Laravel\Passkeys\Contracts\PasskeyUser::class,
            new \App\Models\User
        );
    }
}
