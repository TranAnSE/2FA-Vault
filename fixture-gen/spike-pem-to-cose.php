<?php

/**
 * Spike probe (plan 261009-passkeys-migration, Phase 1): discover the exact
 * JSON contract of webauthn-lib 5.3 CredentialRecord and prove PEM → COSE
 * CBOR conversion end-to-end against the real serializer path.
 *
 * Proves:
 *   1. PEM (ES256 P-256) → COSE map {kty:EC2, alg:ES256, crv:P-256, x, y} → CBOR
 *   2. CredentialRecord construction + WebAuthn::toJson round-trip
 *   3. The exact JSON field names/values the denormalizer expects
 *   4. userHandle handling (undashed 32-hex bytes, red-team C2)
 *
 * Run from app repo root:  php fixture-gen/spike-pem-to-cose.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use Cose\Algorithms;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use SpomkyLabs\Pki\ASN1\Element;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\TrustPath\TrustPath;

echo "=== 1. PEM -> COSE conversion (ES256 P-256) ===\n";

// Generate a real EC P-256 keypair
$keyResource = openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);
openssl_pkey_export($keyResource, $privatePem);
$details = openssl_pkey_get_details($keyResource);
$x = $details['ec']['x']; // 32 raw bytes
$y = $details['ec']['y']; // 32 raw bytes
$publicPem = $details['key'];

echo 'PEM head: ' . substr(str_replace("\n", '', $publicPem), 0, 60) . "...\n";
echo 'x len: ' . strlen($x) . ', y len: ' . strlen($y) . "\n";

// Build the COSE key map for ES256 (per RFC 9052):
//   1 (kty) = 2 (EC2), 3 (alg) = -7 (ES256), -1 (crv) = 1 (P-256), -2 = x, -3 = y
$coseMap = MapObject::create()
    ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
    ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
    ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
    ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))
    ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));
$coseBytes = (string) $coseMap;
echo 'COSE CBOR length: ' . strlen($coseBytes) . " bytes\n";

// Decode back through webauthn-lib's own loader to prove the bytes are valid COSE
$decoder = new \CBOR\Decoder();
$stream = new \CBOR\StringStream($coseBytes);
$decoded = $decoder->decode($stream);
assert($decoded instanceof MapObject);
echo "COSE CBOR decodes via cbor-php Decoder: OK\n";

echo "\n=== 2. CredentialRecord construction + JSON shape ===\n";

$credentialIdRaw = random_bytes(32); // raw credential id bytes
// undashed 32-hex (C2) — the exact byte form laragear transmitted (ramsey Uuid::getHex)
$userHandle = \Ramsey\Uuid\Uuid::uuid4()->getHex()->toString();

$record = CredentialRecord::create(
    publicKeyCredentialId: $credentialIdRaw,
    type: 'public-key',
    transports: ['internal', 'hybrid'],
    attestationType: 'none',
    trustPath: EmptyTrustPath::create(),
    aaguid: \Symfony\Component\Uid\Uuid::fromString('00000000-0000-0000-0000-000000000000'),
    credentialPublicKey: $coseBytes,
    userHandle: $userHandle,
    counter: 0,
);

$json = WebAuthn::toJson($record);
echo "toJson output:\n$json\n";

echo "\n=== 3. Round-trip fromJson ===\n";
$rt = WebAuthn::fromJson($json, CredentialRecord::class);
echo 'id bytes match: ' . var_export($rt->publicKeyCredentialId === $credentialIdRaw, true) . "\n";
echo 'cose bytes match: ' . var_export($rt->credentialPublicKey === $coseBytes, true) . "\n";
echo 'userHandle match: ' . var_export($rt->userHandle === $userHandle, true) . "\n";
echo 'userHandle len: ' . strlen($rt->userHandle) . " (expect 32 = 32 ASCII hex chars = 32 bytes)\n";
echo 'counter: ' . var_export($rt->counter, true) . "\n";
echo 'transports: ' . json_encode($rt->transports) . "\n";
echo 'attestationType: ' . var_export($rt->attestationType, true) . "\n";
echo 'trustPath class: ' . get_class($rt->trustPath) . "\n";
echo 'aaguid: ' . $rt->aaguid->toRfc4122() . "\n";

echo "\n=== 4. RSA (RS256) PEM -> COSE ===\n";
$rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($rsa, $rsaPrivPem);
$rsaDetails = openssl_pkey_get_details($rsa);
$n = $rsaDetails['rsa']['n'];
$e = $rsaDetails['rsa']['e'];
echo 'n len: ' . strlen($n) . " bytes, e len: " . strlen($e) . " bytes\n";

// COSE RSA key: 1 (kty) = 3 (RSA), 3 (alg) = -257 (RS256), -1 = n, -2 = e
$rsaCose = MapObject::create()
    ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(3))
    ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-257))
    ->add(NegativeIntegerObject::create(-1), ByteStringObject::create($n))
    ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($e));
$rsaCoseBytes = (string) $rsaCose;
echo 'RSA COSE CBOR length: ' . strlen($rsaCoseBytes) . " bytes\n";

// Verify webauthn-lib can actually USE the converted key: load it as a Cose key
echo "\n=== 5. Converted COSE key loads as Cose\Key ===\n";
// Use Cose\EC2Key / RSA2Key the way webauthn-lib's authentication steps do
try {
    $coseKeyObj = \CBOR\Decoder::create()->decode(new \CBOR\StringStream($coseBytes));
    $ec2Key = new \Cose\Key\Ec2Key($coseKeyObj->normalize());
    echo 'Ec2Key loads: OK (constructor validated the COSE map)' . "\n";
} catch (Throwable $e) {
    echo 'Ec2Key load FAILED: ' . $e->getMessage() . "\n";
}
try {
    $rsaKeyObj = \CBOR\Decoder::create()->decode(new \CBOR\StringStream($rsaCoseBytes));
    $rsaKey = new \Cose\Key\RsaKey($rsaKeyObj->normalize());
    echo "RsaKey loads: OK\n";
} catch (Throwable $e) {
    echo 'RsaKey load FAILED: ' . $e->getMessage() . "\n";
}

echo "\n=== DONE ===\n";
