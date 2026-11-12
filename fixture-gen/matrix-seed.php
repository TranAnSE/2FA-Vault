<?php

/**
 * Browser-matrix seeder: creates the matrix user and one legacy laragear
 * webauthn_credentials row (ES256) bound to it, so the matrix can run the
 * REAL ETL command and then log in with the converted credential.
 *
 * Bootstraps the framework with the SAME env as the matrix dev server
 * (same APP_KEY — the legacy row's public_key is encrypted at rest).
 *
 * Run with the matrix env sourced:
 *   matrix php fixture-gen/matrix-seed.php
 */

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laragear\WebAuthn\Models\WebAuthnCredential;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Ramsey\Uuid\Uuid;

require __DIR__ . '/../vendor/autoload.php';

foreach (glob(__DIR__ . '/../bootstrap/cache/*.php') as $cached) {
    @unlink($cached);
}

$app = require __DIR__ . '/../bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = User::query()->firstOrNew(['email' => 'matrix-user@rehearsal.test']);

if (! $user->exists) {
    $user->name     = 'Matrix User';
    $user->password = Hash::make('MatrixPassw0rd!');
    $user->save();
}

$privateKey = openssl_pkey_new([
    'curve_name'       => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);

if ($privateKey === false) {
    fwrite(STDERR, 'openssl_pkey_new failed' . PHP_EOL);
    exit(1);
}

$details      = openssl_pkey_get_details($privateKey);
$credentialId = Base64UrlSafe::encodeUnpadded(random_bytes(32));

$point = "\x04" . $details['ec']['x'] . $details['ec']['y'];

$der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $point;

$pem = '-----BEGIN PUBLIC KEY-----' . PHP_EOL
    . chunk_split(base64_encode($der), 64, PHP_EOL)
    . '-----END PUBLIC KEY-----' . PHP_EOL;

$legacy = new WebAuthnCredential;

$legacy->id                   = $credentialId;
$legacy->authenticatable_type = User::class;
$legacy->authenticatable_id   = $user->id;
$legacy->user_id              = Uuid::uuid4()->toString();
$legacy->alias                = 'matrix-legacy-key';
$legacy->counter              = 3;
$legacy->rp_id                = 'localhost';
$legacy->origin               = 'http://localhost:8089';
$legacy->transports           = null;
$legacy->aaguid               = '00000000-0000-0000-0000-000000000000';
$legacy->public_key           = $pem;
$legacy->attestation_format   = 'none';
$legacy->disabled_at          = null;
$legacy->save();

// Export the private key + the laragear user handle for CDP
// WebAuthn.addCredential injection into the virtual authenticator.
// PHP 8.4's openssl_pkey_export already emits PKCS#8 ("BEGIN PRIVATE KEY").
$pkcs8Pem = '';
openssl_pkey_export($privateKey, $pkcs8Pem);

$pkcs8 = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pkcs8Pem));

$handleFile = 'D:/tmp/matrix-credential.json';

file_put_contents($handleFile, json_encode([
    'credentialId' => $credentialId,
    'pkcs8B64Url'  => trim(strtr(base64_encode($pkcs8), '+/', '-_'), '='),
    'userHandle'   => trim(strtr(base64_encode($legacy->user_id), '+/', '-_'), '='),
    'counter'      => (int) $legacy->counter,
]));

echo 'seeded user=' . $user->id . ' legacy-credential=' . substr($credentialId, 0, 12) . '... handle-file=' . $handleFile . PHP_EOL;
