<?php

namespace Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passkeys\Support\WebAuthn;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\Uid\Uuid as SymfonyUuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Synthetic passkey credential/assertion builder for feature tests
 * (plan 261009-passkeys-migration).
 *
 * Generates a real ES256 keypair and seeds a passkeys row in the CONVERTED
 * shape: 24-byte userHandle (base64url-decoded 32-hex uuid, the handle real
 * devices registered with under the legacy flow), COSE public key, fresh
 * counter. Produces assertion JSON a browser would send. The server path
 * (options -> session -> VerifyPasskey) is exercised for real: signature,
 * origin, rpId, challenge, counter and the D7 userVerification=required flag
 * policy are genuinely verified by webauthn-lib.
 *
 * End-to-end proof through a real browser ceremony lives in
 * fixture-gen/matrix-ceremony.mjs (phase 7 R2 gate evidence).
 */
class PasskeyAssertionFactory
{
    /**
     * @return PasskeyCredentialHandle a ceremony handle producing valid assertions
     */
    public static function convertedCredential(Authenticatable $user) : PasskeyCredentialHandle
    {
        $seeded = self::seedConvertedCredential($user);

        return new PasskeyCredentialHandle($seeded['credentialId'], $seeded['privateKey']);
    }

    /**
     * Seed a converted-shape passkeys row WITHOUT returning an assertion
     * handle (for tests exercising options/rename/delete flows).
     */
    public static function convertedCredentialId(Authenticatable $user) : string
    {
        return self::seedConvertedCredential($user)['credentialId'];
    }

    /**
     * Seed a passkeys row in the CONVERTED shape (the v1.6.1+ baseline: no
     * legacy table exists to seed anymore).
     *
     * @return array{credentialId: string, privateKey: OpenSSLAsymmetricKey}
     */
    protected static function seedConvertedCredential(Authenticatable $user) : array
    {
        $privateKey   = self::freshEs256Key();
        $credentialId = Base64UrlSafe::encodeUnpadded(random_bytes(32));

        // OpenSSL intermittently returns truncated/empty EC coordinates
        // under load (observed once per ~1900 tests): the resulting SPKI DER
        // fails to parse downstream. Regenerate until the coordinates are
        // complete 32-byte P-256 components.
        $details = openssl_pkey_get_details($privateKey);

        while ($details === false
            || ! isset($details["ec"]["x"], $details["ec"]["y"])
            || strlen($details["ec"]["x"]) !== 32
            || strlen($details["ec"]["y"]) !== 32) {
            $privateKey = self::freshEs256Key();

            $details = openssl_pkey_get_details($privateKey);
        }

        // Converted rows carry the 24-byte handle real devices registered
        // with under the legacy flow (base64url-decoded 32-hex uuid).
        $hexHandle = Uuid::uuid4()->getHex()->toString();

        $record = CredentialRecord::create(
            publicKeyCredentialId: Base64UrlSafe::decodeNoPadding($credentialId),
            type: 'public-key',
            transports: ['internal', 'hybrid'],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: SymfonyUuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: PemToCoseConverter::convert(self::pemOf($details['ec']['x'], $details['ec']['y'])),
            userHandle: Base64UrlSafe::decodeNoPadding($hexHandle),
            counter: 0,
        );

        $user->passkeys()->create([
            'name'          => 'test-device',
            'credential_id' => $seedingCredentialId = $credentialId,
            'credential'    => json_decode(WebAuthn::toJson($record), true),
            'last_used_at'  => null,
        ]);

        return ['credentialId' => $seedingCredentialId, 'privateKey' => $privateKey];
    }

    /**
     * ES256 SubjectPublicKeyInfo PEM from raw x/y coordinates.
     */
    protected static function pemOf(string $x, string $y) : string
    {
        $point = "\x04" . $x . $y;

        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $point;

        $pem = '-----BEGIN PUBLIC KEY-----' . PHP_EOL;

        $pem .= chunk_split(base64_encode($der), 64, PHP_EOL);

        $pem .= '-----END PUBLIC KEY-----' . PHP_EOL;

        return $pem;
    }

    /**
     * @return OpenSSLAsymmetricKey fresh ES256 (EC P-256) private key
     */
    public static function freshEs256Key() : OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            self::fail();
        }

        return $key;
    }

    protected static function fail() : void
    {
        throw new RuntimeException('openssl_pkey_new failed');
    }
}
