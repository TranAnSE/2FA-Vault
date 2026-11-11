<?php

namespace Tests\Support;

use App\Models\User;
use App\Support\Passkeys\CredentialRecordFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Ramsey\Uuid\Uuid;

/**
 * Synthetic WebAuthn credential/assertion builder for feature tests
 * (plan 261009-passkeys-migration phase 3 R10).
 *
 * Generates a real ES256 keypair, seeds a legacy laragear-format
 * webauthn_credentials row, converts it through the REAL ETL factory, and
 * produces assertion JSON a browser would send. The server path (options ->
 * session -> VerifyPasskey) is exercised for real: signature, origin, rpId,
 * challenge, counter and the D7 userVerification=required flag policy are
 * genuinely verified by webauthn-lib.
 *
 * End-to-end proof through a real browser ceremony lives in
 * fixture-gen/spike-ceremony.mjs (Phase 1 spike R2 gate evidence).
 */
class PasskeyAssertionFactory
{
    /**
     * A real ES256 SPKI PEM (for fixtures needing decryptable-but-failing rows).
     */
    public static function pemFixture() : string
    {
        $key = self::freshEs256Key();

        $details = openssl_pkey_get_details($key);

        return self::pemOf($details['ec']['x'], $details['ec']['y']);
    }
    /**
     * Seed a legacy-format credential for the user, convert it via the real
     * ETL factory, and return a ceremony handle producing valid assertions.
     */
    public static function convertedCredential(Authenticatable $user) : PasskeyCredentialHandle
    {
        $privateKey = self::freshEs256Key();

        $credentialId = Base64UrlSafe::encodeUnpadded(random_bytes(32));

        self::seedLegacyRow($user, $credentialId, $privateKey);

        self::convertLegacyRow($user, $credentialId);

        return new PasskeyCredentialHandle($credentialId, $privateKey);
    }

    /**
     * Seed a legacy-format row and convert it WITHOUT returning an assertion
     * handle (for tests exercising options/rename/delete flows).
     */
    public static function convertedCredentialId(Authenticatable $user) : string
    {
        $privateKey = self::freshEs256Key();

        $credentialId = Base64UrlSafe::encodeUnpadded(random_bytes(32));

        self::seedLegacyRow($user, $credentialId, $privateKey);

        self::convertLegacyRow($user, $credentialId);

        return $credentialId;
    }

    /**
     * Seed a legacy-format row WITHOUT converting it (ETL fixture):
     * returns the credential id and the private key for later assertions.
     *
     * @return array{credentialId: string, privateKey: OpenSSLAsymmetricKey}
     */
    public static function legacyOnly(Authenticatable $user) : array
    {
        $privateKey = self::freshEs256Key();

        $credentialId = Base64UrlSafe::encodeUnpadded(random_bytes(32));

        self::seedLegacyRow($user, $credentialId, $privateKey);

        return ['credentialId' => $credentialId, 'privateKey' => $privateKey];
    }
    /**
     * Seed a legacy laragear-format webauthn_credentials row and convert it
     * into the passkeys table through the REAL ETL factory
     * (CredentialRecordFactory), so the verify path is exercised end-to-end.
     */
    protected static function seedLegacyRow(Authenticatable $user, string $credentialId, OpenSSLAsymmetricKey $privateKey) : void
    {
        $details = openssl_pkey_get_details($privateKey);

        $x = $details['ec']['x'];
        $y = $details['ec']['y'];

        $legacy = new \Laragear\WebAuthn\Models\WebAuthnCredential;
        $legacy->id = $credentialId;
        $legacy->authenticatable_type = User::class;
        $legacy->authenticatable_id = $user->getAuthIdentifier();
        $legacy->user_id = Uuid::uuid4()->toString();
        $legacy->alias = 'legacy-device';
        $legacy->counter = 0;
        $legacy->rp_id = parse_url(config('app.url'), PHP_URL_HOST);
        $legacy->origin = config('app.url');
        $legacy->transports = null;
        $legacy->aaguid = '00000000-0000-0000-0000-000000000000';
        $legacy->public_key = self::pemOf($x, $y);
        $legacy->attestation_format = 'none';
        $legacy->disabled_at = null;
        $legacy->save();

    }

    /**
     * Convert a seeded legacy row into its passkeys row via the real ETL
     * factory.
     */
    protected static function convertLegacyRow(Authenticatable $user, string $credentialId) : void
    {
        $legacy = \Laragear\WebAuthn\Models\WebAuthnCredential::query()->where('id', $credentialId)->firstOrFail();

        $record = CredentialRecordFactory::fromLegacyCredential($legacy);

        $user->passkeys()->create([
            'name' => 'legacy-device',
            'credential_id' => $credentialId,
            'credential' => json_decode(\Laravel\Passkeys\Support\WebAuthn::toJson($record), true),
            'last_used_at' => null,
        ]);
    }

    /**
     * ES256 SubjectPublicKeyInfo PEM from raw x/y coordinates.
     */
    protected static function pemOf(string $x, string $y) : string
    {
        $point = "\x04".$x.$y;

        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00".$point;

        $pem = '-----BEGIN PUBLIC KEY-----'.PHP_EOL;
        $pem .= chunk_split(base64_encode($der), 64, PHP_EOL);
        $pem .= '-----END PUBLIC KEY-----'.PHP_EOL;

        return $pem;
    }

    /**
     * @return OpenSSLAsymmetricKey fresh ES256 (EC P-256) private key
     */
    public static function freshEs256Key() : OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            self::fail('openssl_pkey_new failed');
        }

        return $key;
    }
}
