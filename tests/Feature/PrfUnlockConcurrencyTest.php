<?php

namespace Tests\Feature;

use App\Models\PasskeyPrfCredential;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PasskeyAssertionFactory;
use Tests\Support\PasskeyCredentialHandle;
use Tests\TestCase;

/**
 * PRF stale-writer guards against REAL InnoDB row locks (plan
 * 261119-1650 phase 1).
 *
 * The v1.7.0 enroll/rewrap in-transaction re-checks (PrfUnlockController)
 * were only ever validated on sqlite, which parses and ignores FOR UPDATE —
 * so the lock path itself had never executed. This class proves the guards
 * fire under a genuine row-lock race:
 *
 * - Test A (wiring, enroll + rewrap mirror): a DB::beforeExecuting hook
 *   commits a salt rotation on a SECOND autocommit connection at the exact
 *   moment the controller's `select ... for update` is issued — the window
 *   the guard exists for. The locked re-check must reject with 409 and
 *   store nothing. DB::listen proves the LOCK path ran
 *   (the fast-path 409 and the lock-path 409 are byte-identical, red-team
 *   P1-A: without this the test could green while proving only the
 *   pre-lock guard).
 * - Test B (primitive): one connection's row lock blocks the other's
 *   locked read with a bounded lock-wait timeout.
 *
 * Runs ONLY on a reachable MySQL/MariaDB server with
 * MYSQL_CONCURRENCY_TEST=1 (triple gate — sqlite default suites skip all
 * three tests). Plain TestCase, no refresh trait: the ceremony drives real
 * committed rows, so setUp runs migrate:fresh and seeds outside any
 * wrapping transaction (a FeatureTestCase transaction would turn the
 * controller's DB::transaction into a nested savepoint and hide PDO#2's
 * committed writes).
 */
class PrfUnlockConcurrencyTest extends TestCase
{
    protected User $user;

    protected PDO $secondPdo;

    /** Queries recorded by DB::listen during the request under test. */
    protected array $executedQueries = [];

    protected function setUp() : void
    {
        parent::setUp();

        // Gate 1: real row locks only exist on MySQL/MariaDB (sqlite
        // ignores FOR UPDATE — the exact gap this class closes).
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-only: sqlite parses and ignores FOR UPDATE');
        }

        // Gate 2: explicit opt-in — never runs implicitly on a developer box
        // or in the default CI suites.
        if (env('MYSQL_CONCURRENCY_TEST') !== '1') {
            $this->markTestSkipped('Opt-in concurrency suite: set MYSQL_CONCURRENCY_TEST=1');
        }

        // Gate 3: the second (racer) connection must be reachable.
        try {
            $this->secondPdo = $this->openSecondPdo();
        } catch (PDOException) {
            $this->markTestSkipped('Second PDO connection unreachable — is the MySQL test server up?');
        }

        // Committed schema + rows, no wrapping transaction.
        $this->artisan('migrate:fresh', ['--force' => true]);

        $this->user = User::factory()->create();
        $this->user->forceFill([
            'encryption_enabled'    => true,
            'encryption_salt'       => 'e2ee_salt',
            'encryption_test_value' => '{"ciphertext":"t","iv":"t","authTag":"t"}',
            'encryption_version'    => 1,
        ])->save();

        // Bound any hook/wiring bug to a fast failure instead of a 50s
        // InnoDB stall.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
    }

    protected function tearDown() : void
    {
        unset($this->secondPdo);
        $this->executedQueries = [];

        parent::tearDown();
    }

    // --------------------------------------------------------------- helpers

    /**
     * A second autocommit connection to the SAME database the app talks to —
     * the stale-writer's connection in the race.
     */
    protected function openSecondPdo() : PDO
    {
        $config = config('database.connections.mysql');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * The race interleave: the instant the app issues a `for update` query,
     * the second connection commits a salt rotation out from under the
     * ceremony. Autocommit = the rotation is durable before the locked read
     * executes, and InnoDB locking reads always read the LATEST committed
     * version — so the controller's re-check must observe it.
     */
    protected function armRotationHook() : void
    {
        $userId = $this->user->id;

        DB::beforeExecuting(function (string $query) use ($userId) : void {
            if (str_contains(strtolower($query), 'for update')) {
                // Static literal + seeded int only (red-team F8 — no request
                // data flows into this statement).
                $this->secondPdo->exec(
                    "UPDATE users SET encryption_salt = 'rotated' WHERE id = " . (int) $userId
                );
            }
        });
    }

    /**
     * Record every executed query so the test can PROVE the lock path ran
     * (byte-identical 409s make response-shape assertions insufficient).
     */
    protected function armQueryRecorder() : void
    {
        DB::listen(function ($query) : void {
            $this->executedQueries[] = $query->sql;
        });
    }

    /**
     * The two red-team P1-A proofs, shared by the enroll and rewrap mirrors:
     * exactly one users-row `for update` fired (the guard's lock site), i.e.
     * the lock path — not just the pre-lock fast path — ran before the 409.
     * The enroll path's VerifyPasskey passkey-row `for update` is a
     * legitimate SECOND lock site and must not break this assertion.
     */
    protected function assertLockPathExecuted() : void
    {
        $usersLocks = array_filter(
            $this->executedQueries,
            fn ($sql) => str_contains(strtolower($sql), 'for update') && str_contains($sql, '`users`')
        );

        $this->assertCount(
            1,
            $usersLocks,
            'Expected exactly one users-row `for update` — 0 means the lock path never ran, >1 means an unexpected lock site. All for-update SQL: '
            . json_encode($this->executedQueries)
        );
    }

    /**
     * Drive the real enroll ceremony: options -> signed assertion -> enroll
     * (helper shape copied from PrfUnlockTest).
     */
    protected function enrollThroughCeremony(User $user, PasskeyCredentialHandle $handle, array $blobs = [])
    {
        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');

        $challenge = $this->postJson('/api/v1/encryption/prf/enroll/options', [
            'credential_id' => $handle->credentialId,
        ])->json('challenge');

        $assertion = $handle->assertion($challenge);

        return $this->postJson('/api/v1/encryption/prf/enroll', array_merge([
            'credential_id'                 => $handle->credentialId,
            'expected_encryption_salt_hash' => hash('sha256', 'e2ee_salt'),
            'assertion'                     => $assertion['credential'],
            'public_key'                    => str_repeat('P', 100),
            'wrapped_private_key'           => str_repeat('W', 100),
            'wrapped_vault_key'             => str_repeat('V', 100),
        ], $blobs));
    }

    /**
     * Seed a PRF enrollment row directly (helper shape copied from
     * PrfUnlockTest).
     */
    protected function seedEnrollment(User $user) : PasskeyPrfCredential
    {
        $credentialId = PasskeyAssertionFactory::convertedCredentialId($user);

        $passkey = $user->passkeys()->where('credential_id', $credentialId)->first();

        $enrollment                      = new PasskeyPrfCredential;
        $enrollment->user_id             = $user->id;
        $enrollment->passkey_id          = $passkey->id;
        $enrollment->public_key          = str_repeat('A', 100);
        $enrollment->wrapped_private_key = str_repeat('B', 100);
        $enrollment->wrapped_vault_key   = str_repeat('C', 100);
        $enrollment->save();

        return $enrollment;
    }

    // ------------------------------------------------------ test A: wiring

    #[Test]
    public function test_enroll_lock_recheck_rejects_a_salt_rotated_mid_ceremony()
    {
        $handle = PasskeyAssertionFactory::convertedCredential($this->user);

        $this->armQueryRecorder();
        $this->armRotationHook();

        $this->enrollThroughCeremony($this->user, $handle)->assertStatus(409);

        // Nothing stored — the stale writer lost the race.
        $this->assertSame(0, PasskeyPrfCredential::count());

        // The rotation is durable and the guard saw it (not the pre-rotation
        // view): the locked re-check read the committed value.
        $this->assertSame('rotated', $this->user->fresh()->encryption_salt);

        $this->assertLockPathExecuted();
    }

    #[Test]
    public function test_rewrap_lock_recheck_rejects_a_salt_rotated_mid_request()
    {
        $enrollment = $this->seedEnrollment($this->user);
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $this->armQueryRecorder();
        $this->armRotationHook();

        $this->postJson('/api/v1/encryption/prf/rewrap', [
            'expected_encryption_salt_hash' => hash('sha256', 'e2ee_salt'),
            'wrappings'                     => [
                ['credential_id' => $enrollment->passkey->credential_id, 'wrapped_vault_key' => 'mid-race-wrap'],
            ],
        ])->assertStatus(409);

        // The stale wrapping never landed.
        $enrollment->refresh();
        $this->assertSame(str_repeat('C', 100), $enrollment->wrapped_vault_key);

        $this->assertSame('rotated', $this->user->fresh()->encryption_salt);

        $this->assertLockPathExecuted();
    }

    // -------------------------------------------------- test B: primitive

    #[Test]
    public function test_innodb_row_lock_blocks_a_concurrent_locked_read()
    {
        // PDO#2 acquires and HOLDS the user row lock (open transaction).
        $this->secondPdo->beginTransaction();
        $hold = $this->secondPdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $hold->execute([$this->user->id]);
        $this->assertSame($this->user->id, (int) $hold->fetch()['id']);

        // The default connection's locked read must block until the bound
        // 2s lock-wait timeout — a genuine cross-connection row lock.
        try {
            User::query()->whereKey($this->user->id)->lockForUpdate()->first();
            $this->fail('Expected a lock-wait QueryException — the row lock did NOT block the locked read.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('1205', $e->getMessage(), 'Expected InnoDB lock-wait timeout (error 1205).');
        }

        // PDO#2 commits its rotation; the (previously blocked) locked read
        // must now observe the committed value — locking reads bypass the
        // REPEATABLE READ snapshot.
        $this->secondPdo->exec("UPDATE users SET encryption_salt = 'rotated' WHERE id = " . (int) $this->user->id);
        $this->secondPdo->commit();

        $locked = User::query()->whereKey($this->user->id)->lockForUpdate()->first();
        $this->assertSame('rotated', $locked->encryption_salt);
    }
}
