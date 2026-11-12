<?php

/**
 * Latency comparison (plan 261009-passkeys-migration phase 7 R3).
 *
 * Like-for-like micro-benchmark of the two ceremony validators, sharing ONE
 * ES256 keypair and credential id seeded into BOTH storage formats:
 *
 *  - OLD: laragear AssertionValidator pipeline, driven through its production
 *    entry point (challenge in the session repository) exactly as the
 *    pre-migration login flow ran it.
 *  - NEW: laravel/passkeys VerifyPasskey action, with the credential JSON
 *    deserialization included (the controller deserializes inside the login
 *    attempt) and a fresh PublicKeyCredentialRequestOptions per iteration.
 *
 * The M7 anti-enumeration usleep floor lives in the login CONTROLLER on both
 * branches and is deliberately out of scope here: this measures validator
 * work only. The options-generation path intentionally gained the M7 floor
 * and is also out of scope by design.
 *
 * Run: php fixture-gen/latency-bench.php [iterations]
 * 
 * REQUIRES the v1.6.0 tag (laragear/webauthn + the ETL factory still present):
 * the OLD-path validator and CredentialRecordFactory were removed in v1.6.1.
 * Also reads config/webauthn.php (deleted in v1.6.1). Evidence reproduced from
 * the feat/passkeys-migration checkout.

use App\Models\User;
use App\Support\Passkeys\CredentialRecordFactory;
use Illuminate\Support\Facades\Artisan;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidation;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidator;
use Laragear\WebAuthn\ByteBuffer;
use Laragear\WebAuthn\Challenge\Challenge;
use Laragear\WebAuthn\JsonTransport;
use Laragear\WebAuthn\Models\WebAuthnCredential;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\PasskeyAssertionFactory;
use Tests\Support\PasskeyCredentialHandle;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;

$_ENV['APP_NAME']         = '2FA Vault';
$_ENV['APP_ENV']          = 'production';
$_ENV['APP_DEBUG']        = 'false';
$_ENV['APP_KEY']          = 'base64:' . base64_encode(random_bytes(32));
$_ENV['APP_URL']          = 'http://localhost';
$_ENV['DB_CONNECTION']    = 'sqlite';
$_ENV['DB_DATABASE']      = ':memory:';
$_ENV['SESSION_DRIVER']   = 'array';
$_ENV['CACHE_DRIVER']     = 'array';
$_ENV['CACHE_STORE']      = 'array';
$_ENV['QUEUE_CONNECTION'] = 'sync';
$_ENV['MAIL_MAILER']      = 'log';

require __DIR__ . '/../vendor/autoload.php';

// A concurrently running phpunit process can leave a bootstrap config cache
// behind, whose baked values would override every env var set here (observed
// during the rehearsal). Local standalone runs must start cache-free.
foreach (glob(__DIR__ . '/../bootstrap/cache/*.php') as $cached) {
    @unlink($cached);
}

$app = require __DIR__ . '/../bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Artisan::call('migrate', ['--force' => true]);

$user = User::factory()->create([
    'email' => 'bench@rehearsal.test',
]);

// The branch's User model no longer carries laragear's WebAuthnAuthentication
// trait (the migration removed it); the OLD-path validator is contract-typed,
// so the benchmark emulates the pre-migration User via a subclass carrying
// the same trait — identical table, relation and queries as the old flow.
$benchUserClass = get_class(new class extends User implements \Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable {
    use \Laragear\WebAuthn\WebAuthnAuthentication;

    // Anonymous class names are garbage to Eloquent's getTable() heuristic.
    protected $table = 'users';
});

$benchUser = (new $benchUserClass)->query()->findOrFail($user->id);

// One ES256 keypair + credential id seeded into BOTH storage formats, bound
// to the subclass morph type (the old-path pipe matches on it).
$privateKey   = PasskeyAssertionFactory::freshEs256Key();
$credentialId = Base64UrlSafe::encodeUnpadded(random_bytes(32));
$details      = openssl_pkey_get_details($privateKey);

$legacy = new WebAuthnCredential;

$legacy->id                   = $credentialId;
$legacy->authenticatable_type = $benchUserClass;
$legacy->authenticatable_id   = $user->id;
$legacy->user_id              = Ramsey\Uuid\Uuid::uuid4()->toString();
$legacy->alias                = 'bench-key';
$legacy->counter              = 0;
$legacy->rp_id                = 'localhost';
$legacy->origin               = 'http://localhost';
$legacy->transports           = null;
$legacy->aaguid               = '00000000-0000-0000-0000-000000000000';
$legacy->public_key           = self_pemOf($details['ec']['x'], $details['ec']['y']);
$legacy->attestation_format   = 'none';
$legacy->disabled_at          = null;
$legacy->save();

$legacyRow = WebAuthnCredential::query()->where('id', $credentialId)->firstOrFail();
$record    = CredentialRecordFactory::fromLegacyCredential($legacyRow);

$user->passkeys()->create([
    'name'          => 'bench-key',
    'credential_id' => $credentialId,
    'credential'    => json_decode(WebAuthn::toJson($record), true),
    'last_used_at'  => null,
]);

$handle    = new PasskeyCredentialHandle($credentialId, $privateKey);
$validator = app(AssertionValidator::class);
$verify    = app(VerifyPasskey::class);
$rpId      = parse_url(config('app.url'), PHP_URL_HOST);

$iterations = max(10, (int) ($_SERVER['argv'][1] ?? 300));

$oldTimes = [];
$newTimes = [];

function medianP95(array $times) : array
{
    sort($times);

    return [$times[(int) (count($times) * 0.5)], $times[(int) (count($times) * 0.95)]];
}

/** ES256 SubjectPublicKeyInfo PEM from raw x/y coordinates. */
function self_pemOf(string $x, string $y) : string
{
    $point = "\x04" . $x . $y;

    $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $point;

    $pem = '-----BEGIN PUBLIC KEY-----' . PHP_EOL;

    $pem .= chunk_split(base64_encode($der), 64, PHP_EOL);

    $pem .= '-----END PUBLIC KEY-----' . PHP_EOL;

    return $pem;
}

for ($i = 0; $i < $iterations; $i++) {
    // --- OLD path: laragear pipeline, session-held challenge
    $challengeB64 = Base64UrlSafe::encodeUnpadded(random_bytes(32));
    $challengeRaw = Base64UrlSafe::decode($challengeB64, false);

    session()->put(config('webauthn.challenge.key'), new Challenge(new ByteBuffer($challengeRaw), (int) config('webauthn.challenge.timeout')));

    $t0 = hrtime(true);

    try {
        $validator->send(new AssertionValidation(new JsonTransport($handle->assertion($challengeB64)['credential']), $benchUser))->thenReturn();
    } catch (Throwable $e) {
        fwrite(STDERR, 'OLD path failed at iteration ' . $i . ': ' . get_class($e) . ' ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    $oldTimes[] = (hrtime(true) - $t0) / 1e6;

    // --- NEW path: VerifyPasskey incl. credential JSON deserialization
    $challengeB64 = Base64UrlSafe::encodeUnpadded(random_bytes(32));
    $challengeRaw = Base64UrlSafe::decode($challengeB64, false);

    $options = new PublicKeyCredentialRequestOptions(
        $challengeRaw,
        $rpId,
        [new PublicKeyCredentialDescriptor('public-key', Base64UrlSafe::decodeNoPadding($credentialId))],
        PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
    );

    $t0 = hrtime(true);

    try {
        $verify(
            WebAuthn::fromJson(json_encode($handle->assertion($challengeB64)['credential'], JSON_THROW_ON_ERROR), PublicKeyCredential::class),
            $options,
            $benchUser
        );
    } catch (Throwable $e) {
        fwrite(STDERR, 'NEW path failed at iteration ' . $i . ': ' . get_class($e) . ' ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    $newTimes[] = (hrtime(true) - $t0) / 1e6;
}

[$oldP50, $oldP95] = medianP95($oldTimes);
[$newP50, $newP95] = medianP95($newTimes);

printf(
    "iterations=%d%s" .
    "OLD laragear AssertionValidator:  p50=%.2fms  p95=%.2fms  mean=%.2fms  min=%.2fms  max=%.2fms%s" .
    "NEW VerifyPasskey:                p50=%.2fms  p95=%.2fms  mean=%.2fms  min=%.2fms  max=%.2fms%s" .
    "p50 ratio (new/old) = %.2f%s",
    $iterations,
    PHP_EOL,
    $oldP50, $oldP95, array_sum($oldTimes) / count($oldTimes), min($oldTimes), max($oldTimes), PHP_EOL,
    $newP50, $newP95, array_sum($newTimes) / count($newTimes), min($newTimes), max($newTimes), PHP_EOL,
    $newP50 / $oldP50,
    PHP_EOL
);
