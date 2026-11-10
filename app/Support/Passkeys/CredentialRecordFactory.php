<?php

namespace App\Support\Passkeys;

use Laragear\WebAuthn\Models\WebAuthnCredential;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

class CredentialRecordFactory
{
    public const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    public const MAX_CREDENTIAL_ID_LENGTH = 255;

    /**
     * Build the CredentialRecord for a legacy credential row.
     *
     * @throws RuntimeException on decrypt/parse/conversion failure
     */
    public static function fromLegacyCredential(WebAuthnCredential $credential): CredentialRecord
    {
        // laragear's `encrypted` cast decrypts on attribute access: the
        // model attribute IS the PEM already (no manual Crypt::decryptString).
        $pem = $credential->public_key;

        if (! is_string($pem) || ! str_contains($pem, 'BEGIN PUBLIC KEY')) {
            throw new RuntimeException('Decrypted payload is not a PEM public key.');
        }

        $coseKeyBytes = PemToCoseConverter::convert($pem);

        $record = CredentialRecord::create(
            publicKeyCredentialId: Base64UrlSafe::decodeNoPadding($credential->id),
            type: 'public-key',
            transports: is_array($credential->transports) ? $credential->transports : [],
            attestationType: $credential->attestation_format ?? 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: \Symfony\Component\Uid\Uuid::fromString($credential->aaguid ?? self::NIL_UUID),
            credentialPublicKey: $coseKeyBytes,
            userHandle: self::deviceUserHandle($credential->user_id),
            counter: (int) ($credential->counter ?? 0),
        );

        return $record;
    }

    /**
     * The userHandle bytes the AUTHENTICATOR actually holds.
     *
     * Spike finding (corrects red-team C2): laragear transmitted user.id as a
     * plain 32-char hex STRING, but the fork SPA base64url-decodes it
     * (webauthnService.uint8Array -> base64UrlDecode) before handing it to
     * navigator.credentials.create. Real devices therefore registered with
     * base64UrlDecode(hex) as their userHandle -- 24 deterministic bytes, NOT
     * the hex bytes. Converted records must carry exactly those bytes or any
     * assertion that echoes a userHandle fails CheckUserHandle.
     */
    public static function deviceUserHandle(string $dashedUuid): string
    {
        $hex = Uuid::fromString($dashedUuid)->getHex()->toString();

        return Base64UrlSafe::decodeNoPadding($hex);
    }

    /**
     * The serialized form stored in the passkeys.credential JSON column
     * (exactly what VerifyPasskey deserializes on the login path).
     */
    public static function toCredentialJson(CredentialRecord $record): string
    {
        return WebAuthn::toJson($record);
    }
}
