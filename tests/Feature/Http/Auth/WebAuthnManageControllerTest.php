<?php

namespace Tests\Feature\Http\Auth;

use App\Http\Controllers\Auth\WebAuthnManageController;
use App\Http\Middleware\RejectIfReverseProxy;
use App\Models\Traits\WebAuthnManageCredentials;
use App\Models\User;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;
use Tests\Support\PasskeyAssertionFactory;

/**
 * WebAuthnManageControllerTest test class
 */
#[CoversClass(WebAuthnManageController::class)]
#[CoversClass(RejectIfReverseProxy::class)]
#[CoversTrait(WebAuthnManageCredentials::class)]
class WebAuthnManageControllerTest extends FeatureTestCase
{
    // use WithoutMiddleware;

    /**
     * @var \App\Models\User|\Illuminate\Contracts\Auth\Authenticatable
     */
    protected $user;

    public const CREDENTIAL_ID = '-VOLFKPY-_FuMI_sJ7gMllK76L3VoRUINj6lL_Z3qDg';

    public const CREDENTIAL_ID_RAW = '+VOLFKPY+/FuMI/sJ7gMllK76L3VoRUINj6lL/Z3qDg=';

    protected function setUp() : void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function test_index_returns_success_with_credentials()
    {
        $credentialId = PasskeyAssertionFactory::convertedCredentialId($this->user);

        $response = $this->actingAs($this->user, 'web-guard')
            ->json('GET', '/webauthn/credentials')
            ->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'alias',
                    'aaguid',
                    'last_used_at',
                ],
            ]);

        // C7 (plan 261009): the SPA list fields are preserved on the
        // passkeys-backed index.
        $this->assertSame($credentialId, $response->json('0.id'));
        $this->assertSame('test-device', $response->json('0.alias'));
    }

    #[Test]
    public function test_rename_returns_success_with_new_name()
    {
        $credentialId = PasskeyAssertionFactory::convertedCredentialId($this->user);

        $response = $this->actingAs($this->user, 'web-guard')
            ->json('PATCH', '/webauthn/credentials/' . $credentialId . '/name', [
                'name' => 'MyNewCredential',
            ])
            ->assertStatus(200)
            ->assertExactJson([
                'name' => 'MyNewCredential',
            ]);
    }

    #[Test]
    public function test_rename_invalid_data_returns_validation_error()
    {
        $response = $this->actingAs($this->user, 'web-guard')
            ->json('PATCH', '/webauthn/credentials/' . self::CREDENTIAL_ID . '/name', [
                'name' => null,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function test_rename_missing_credential_returns_not_found()
    {
        $response = $this->actingAs($this->user, 'web-guard')
            ->json('PATCH', '/webauthn/credentials/unknown/name', [
                'name' => 'MyNewCredential',
            ])
            ->assertNotFound()
            ->assertJsonStructure([
                'message',
            ]);
    }

    #[Test]
    public function test_index_as_reverse_proxy_returns_error()
    {
        $response = $this->actingAs($this->user, 'reverse-proxy-guard')
            ->json('GET', '/webauthn/credentials')
            ->assertStatus(405);
    }

    #[Test]
    public function test_rename_as_reverse_proxy_returns_error()
    {
        $response = $this->actingAs($this->user, 'reverse-proxy-guard')
            ->json('PATCH', '/webauthn/credentials/fqsdfqsdf/name')
            ->assertStatus(405);
    }

    #[Test]
    public function test_delete_as_reverse_proxy_returns_error()
    {
        $response = $this->actingAs($this->user, 'reverse-proxy-guard')
            ->json('DELETE', '/webauthn/credentials/dcnskldjnkljsrn')
            ->assertStatus(405);
    }

    #[Test]
    public function test_delete_returns_no_content()
    {
        $response = $this->actingAs($this->user, 'web-guard')
            ->json('DELETE', '/webauthn/credentials/sdCKktnsdK')
            ->assertNoContent();
    }
}
