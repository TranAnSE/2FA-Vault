<?php

namespace Tests\Unit\Support;

use App\Models\User;
use App\Support\Passkeys\CredentialRecordFactory;
use App\Support\Passkeys\PemToCoseConverter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Support\PasskeyAssertionFactory;
use Webauthn\CredentialRecord;

/**
 * Converter + factory unit tests (plan 261009-passkeys-migration phase 4):
 * each supported algorithm class must produce COSE bytes that survive the
 * real denormalization round-trip with intact key material.
 */
#[CoversClass(PemToCoseConverter::class)]
#[CoversClass(CredentialRecordFactory::class)]
class PasskeyEtlTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function es256_pem_converts_to_ec2_cose_round_trip() : void
    {
        $pem = PasskeyAssertionFactory::pemFixture();

        $cose = PemToCoseConverter::convert($pem);

        $record = $this->recordWithKey($cose);

        $this->assertSame(PemToCoseConverter::ALG_ES256, $this->algOf($record));
    }

    #[Test]
    public function rs256_pem_converts_to_rsa_cose_round_trip() : void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        openssl_pkey_export($key, $privateOut);

        $details = openssl_pkey_get_details($key);

        $pem = $details['key'];

        $cose = PemToCoseConverter::convert($pem);

        $record = $this->recordWithKey($cose);

        $this->assertSame(PemToCoseConverter::ALG_RS256, $this->algOf($record));
    }

    #[Test]
    public function malformed_pem_is_rejected() : void
    {
        $this->expectException(\RuntimeException::class);

        PemToCoseConverter::convert('not a pem');
    }

    #[Test]
    public function legacy_row_converts_to_verifiable_record() : void
    {
        $user = User::factory()->create();

        $fixture = PasskeyAssertionFactory::legacyOnly($user);

        $legacy = \Laragear\WebAuthn\Models\WebAuthnCredential::query()->where('id', $fixture['credentialId'])->firstOrFail();

        $record = CredentialRecordFactory::fromLegacyCredential($legacy);

        // Round-trip: what lands in passkeys.credential must deserialize
        // into the same record the login path verifies against.
        $restored = WebAuthn::fromJson(CredentialRecordFactory::toCredentialJson($record), CredentialRecord::class);

        $this->assertSame($fixture['credentialId'], Base64UrlSafe::encodeUnpadded($restored->publicKeyCredentialId));
        $this->assertSame(24, strlen($restored->userHandle));
        $this->assertGreaterThan(0, strlen($restored->credentialPublicKey));
    }

    #[Test]
    public function device_user_handle_decodes_the_hex_id() : void
    {
        $handle = CredentialRecordFactory::deviceUserHandle('3b758ac868b74307a7e96e69ae187339');

        // C2 correction: base64UrlDecode of the 32-hex id -> 24 bytes.
        $this->assertSame(24, strlen($handle));
    }

    protected function recordWithKey(string $coseBytes) : CredentialRecord
    {
        $record = CredentialRecord::create(
            publicKeyCredentialId: random_bytes(32),
            type: 'public-key',
            transports: [],
            attestationType: 'none',
            trustPath: \Webauthn\TrustPath\EmptyTrustPath::create(),
            aaguid: \Symfony\Component\Uid\Uuid::fromString(CredentialRecordFactory::NIL_UUID),
            credentialPublicKey: $coseBytes,
            userHandle: random_bytes(24),
            counter: 0,
        );

        return WebAuthn::fromJson(WebAuthn::toJson($record), CredentialRecord::class);
    }

    protected function algOf(CredentialRecord $record) : int
    {
        $decoded = \CBOR\Decoder::create()->decode(
            \CBOR\StringStream::create($record->credentialPublicKey)
        );

        if ($decoded->has(3)) {

            return (int) $decoded->get(3)->getValue();
        }

        return 0;
    }
}
