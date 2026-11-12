<?php

/**
 * Staging rehearsal seeder (plan 261009 phase 6 R2): seeds REAL laragear-format
 * legacy rows (encrypted PEM behind the `encrypted` cast) against the staging
 * MySQL DB, mimicking a production copy. Run with the staging env vars set.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base64UrlSafe;

$users = [];
foreach (['alice@rehearsal.test', 'bob@rehearsal.test', 'carol@rehearsal.test'] as $email) {
    $users[$email] = App\Models\User::factory()->create(['email' => $email, 'password' => Illuminate\Support\Facades\Hash::make('rehearsal-password-A1')]);
}

function es256Pem() : string
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $details = openssl_pkey_get_details($key);

    $point = "\x04".$details['ec']['x'].$details['ec']['y'];
    $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00".$point;

    return '-----BEGIN PUBLIC KEY-----'.PHP_EOL.chunk_split(base64_encode($der), 64, PHP_EOL).'-----END PUBLIC KEY-----';
}

function rs256Pem() : string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($key);

    return $details['key'];
}

function ed25519Pem() : ?string
{
    $key = @openssl_pkey_new(['curve_name' => 'ed25519', 'private_key_type' => OPENSSL_KEYTYPE_ED25519]);

    if ($key === false) {
        return null;
    }

    return openssl_pkey_get_details($key)['key'] ?? null;
}

$specs = [
    ['email' => 'alice@rehearsal.test', 'pem' => es256Pem(), 'counter' => 3, 'transports' => json_encode(['internal', 'hybrid']), 'alias' => 'Alice iPhone'],
    ['email' => 'alice@rehearsal.test', 'pem' => rs256Pem(), 'counter' => 0, 'transports' => null, 'alias' => null],
    ['email' => 'bob@rehearsal.test', 'pem' => es256Pem(), 'counter' => 41, 'transports' => json_encode(['usb']), 'alias' => 'Bob YubiKey'],
    ['email' => 'carol@rehearsal.test', 'pem' => ed25519Pem() ?? es256Pem(), 'counter' => null, 'transports' => json_encode(['internal']), 'alias' => 'Carol key'],
];

foreach ($specs as $spec) {
    $credId = Base64UrlSafe::encodeUnpadded(random_bytes(32));

    $row = new Laragear\WebAuthn\Models\WebAuthnCredential;
    $row->id = $credId;
    $row->authenticatable_type = App\Models\User::class;
    $row->authenticatable_id = $users[$spec['email']]->id;
    $row->user_id = Ramsey\Uuid\Uuid::uuid4()->toString();
    $row->alias = $spec['alias'];
    $row->counter = $spec['counter'];
    $row->rp_id = 'localhost';
    $row->origin = config('app.url');
    $row->transports = $spec['transports'] ? json_decode($spec['transports']) : null;
    $row->aaguid = $spec['alias'] === 'Bob YubiKey' ? '2fc0579f-8183-47d9-a0c5-fa9a3e682bed' : '00000000-0000-0000-0000-000000000000';
    $row->public_key = $spec['pem'];
    $row->attestation_format = 'none';
    $row->disabled_at = null;
    $row->save();

    echo "seeded {$spec['email']} cred=".substr($credId, 0, 12)."... counter={$spec['counter']} transports=".var_export($spec['transports'], true)."\n";
}

echo 'legacy rows total: '.DB::table('webauthn_credentials')->count()."\n";
