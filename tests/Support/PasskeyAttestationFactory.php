<?php

namespace Tests\Support;

use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * Synthetic WebAuthn REGISTRATION (attestation) builder for feature tests.
 *
 * Produces a browser-style PublicKeyCredential JSON payload whose
 * attestationObject is a hand-encoded CBOR map {fmt: none, attStmt: {},
 * authData} carrying attested credential data (aaguid, credential id and a
 * COSE EC2/ES256 public key). The server-side StorePasskey action verifies
 * the ceremony for real through webauthn-lib: origin, rpId hash, challenge,
 * UP|UV|AT flags and COSE key parsing are all genuinely checked.
 */
class PasskeyAttestationFactory
{
    /**
     * @return array{id: string, rawId: string, type: string, response: array}
     */
    public static function create(string $challengeB64Url) : array
    {
        $keyPair = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        $details = openssl_pkey_get_details($keyPair);

        $credentialId = random_bytes(32);

        $clientDataJson = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challengeB64Url,
            'origin' => config('app.url'),
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);

        $authData = self::buildAuthData($credentialId, $details['ec']['x'], $details['ec']['y']);

        $attestationObject = self::cborMap([
            self::cborText('fmt') => self::cborText('none'),
            self::cborText('attStmt') => "\xa0",
            self::cborText('authData') => self::cborBytes($authData),
        ]);

        $idB64Url = Base64UrlSafe::encodeUnpadded($credentialId);

        return [
            'id' => $idB64Url,
            'rawId' => $idB64Url,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJson),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
                'transports' => ['internal'],
            ],
        ];
    }

    /**
     * rpIdHash | flags(UP|UV|AT) | signCount | aaguid | credIdLen | credId | COSE key
     */
    protected static function buildAuthData(string $credentialId, string $x, string $y) : string
    {
        $rpIdHash = hash('sha256', parse_url(config('app.url'), PHP_URL_HOST), true);

        $flags = chr(0x45); // UP | UV | AT

        $signCount = pack('N', 0);

        $aaguid = str_repeat(chr(0x00), 16);

        $coseKey = self::coseEc2Key($x, $y);

        return $rpIdHash.$flags.$signCount.$aaguid.pack('n', strlen($credentialId)).$credentialId.$coseKey;
    }

    /**
     * COSE EC2 key CBOR: {1: 2 (kty EC2), 3: -7 (alg ES256), -1: 1 (crv
     * P-256), -2: x, -3: y}
     */
    protected static function coseEc2Key(string $x, string $y) : string
    {
        return self::cborMap([
            self::cborUint(1) => self::cborUint(2),
            self::cborUint(3) => self::cborNegative(7),
            self::cborNegative(1) => self::cborUint(1),
            self::cborNegative(2) => self::cborBytes($x),
            self::cborNegative(3) => self::cborBytes($y),
        ]);
    }

    /** RFC 8949 primitives (deterministic, short forms only). */
    protected static function cborUint(int $v) : string
    {
        return chr($v);
    }

    protected static function cborNegative(int $magnitude) : string
    {
        return chr(0x20 + $magnitude - 1);
    }

    protected static function cborText(string $s) : string
    {
        return chr(0x60 + strlen($s)).$s;
    }

    protected static function cborBytes(string $b) : string
    {
        if (strlen($b) < 24) {
            return chr(0x40 + strlen($b)).$b;
        }

        return chr(0x58).chr(strlen($b)).$b;
    }

    /**
     * @param array<string, string> $pairs already-encoded key => value bytes
     */
    protected static function cborMap(array $pairs) : string
    {
        return chr(0xA0 + count($pairs)).implode('', array_map(
            fn ($k, $v) => $k.$v,
            array_keys($pairs),
            array_values($pairs)
        ));
    }
}
