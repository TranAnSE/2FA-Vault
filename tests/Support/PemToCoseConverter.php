<?php

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use RuntimeException;

class PemToCoseConverter
{
    public const KTY_EC2 = 2;

    public const KTY_RSA = 3;

    public const KTY_OKP = 1;

    public const ALG_ES256 = -7;

    public const ALG_RS256 = -257;

    public const ALG_EDDSA = -8;

    public const CRV_P256 = 1;

    public const CRV_ED25519 = 6;

    /**
     * Convert a PEM public key to raw COSE CBOR key bytes.
     *
     * @throws RuntimeException on malformed PEM or unsupported key class
     */
    public static function convert(string $pem) : string
    {
        $details = self::keyDetails($pem);

        $kty = self::ktyFor($details['type']);

        $map = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create($kty))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(self::algFor($kty)));

        foreach (self::coseParams($details, $pem) as $label => $value) {
            $key = $label < 0
                ? NegativeIntegerObject::create($label)
                : UnsignedIntegerObject::create($label);
            $map->add($key, is_int($value) ? UnsignedIntegerObject::create($value) : ByteStringObject::create($value));
        }

        return (string) $map;
    }

    /**
     * Map the openssl key class to the COSE kty label.
     */
    protected static function ktyFor(int $opensslType) : int
    {
        return match ($opensslType) {
            OPENSSL_KEYTYPE_EC      => self::KTY_EC2,
            OPENSSL_KEYTYPE_RSA     => self::KTY_RSA,
            OPENSSL_KEYTYPE_ED25519 => self::KTY_OKP,
            default                 => throw new RuntimeException(sprintf('Unsupported openssl key type %d.', $opensslType)),
        };
    }

    /**
     * The COSE alg label implied by each supported key class.
     */
    public static function algFor(int $kty) : int
    {
        return match ($kty) {
            self::KTY_EC2 => self::ALG_ES256,
            self::KTY_RSA => self::ALG_RS256,
            self::KTY_OKP => self::ALG_EDDSA,
            default       => throw new RuntimeException('Unsupported COSE key type ' . $kty),
        };
    }

    /**
     * Extract key parameters from the PEM.
     *
     * @return array<string, mixed> openssl_pkey_get_details output
     */
    protected static function keyDetails(string $pem) : array
    {
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new RuntimeException('Unparseable public key PEM.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw new RuntimeException('Unparseable public key details.');
        }

        return $details;
    }

    /**
     * COSE parameter labels per key class.
     *
     * @return array<int, mixed>
     */
    protected static function coseParams(array $details, string $pem) : array
    {
        return match ($details['type']) {
            OPENSSL_KEYTYPE_EC      => self::ec2Params($details),
            OPENSSL_KEYTYPE_RSA     => self::rsaParams($details),
            OPENSSL_KEYTYPE_ED25519 => self::okpParams($pem),
            default                 => throw new RuntimeException(sprintf('Unsupported key class %d.', $details['type'])),
        };
    }

    /**
     * @return array<int, mixed>
     */
    protected static function ec2Params(array $details) : array
    {
        return [
            -1 => self::CRV_P256,
            -2 => $details['ec']['x'],
            -3 => $details['ec']['y'],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function rsaParams(array $details) : array
    {
        return [
            -1 => $details['rsa']['n'],
            -2 => $details['rsa']['e'],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function okpParams(string $pem) : array
    {
        // Ed25519 SPKI DER has a fixed prefix; the 32 raw key bytes are the DER tail.
        return [
            -1 => self::CRV_ED25519,
            -2 => substr(self::derFromPem($pem), -32),
        ];
    }

    protected static function derFromPem(string $pem) : string
    {
        $body = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'], '', $pem);

        $decoded = base64_decode(trim($body), true);

        if ($decoded === false) {
            throw new RuntimeException('Unparseable PEM body.');
        }

        return $decoded;
    }
}
