<?php

namespace Tests\Unit;

use App\Enums\PersonalAction;
use App\Models\PersonalActivityLog;
use App\Models\User;
use App\Services\PersonalActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PersonalActivityLoggerTest test class
 */
#[CoversClass(PersonalActivityLogger::class)]
class PersonalActivityLoggerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_log_persists_an_activity_log_record()
    {
        $user = User::factory()->create();
        $account = \App\Models\TwoFAccount::factory()->forUser($user)->create();
        $logger = new PersonalActivityLogger();

        $logger->log($user, PersonalAction::ACCOUNT_CREATED, ['source' => 'test'], $account->id);

        $this->assertDatabaseHas('personal_activity_logs', [
            'user_id' => $user->id,
            'action' => PersonalAction::ACCOUNT_CREATED->value,
            'target_account_id' => $account->id,
        ]);

        $log = PersonalActivityLog::first();
        $this->assertNotNull($log);
        $this->assertSame(['source' => 'test'], $log->metadata);
    }

    #[Test]
    public function test_log_swallows_database_failures_silently()
    {
        $user = User::factory()->create();

        // Force a real DB error: target_account_id has an FK to twofaccounts.
        // A non-existent ID violates the constraint, so create() throws — and
        // the logger's try/catch must absorb it (fire-and-forget contract).
        $logger = new PersonalActivityLogger();
        $logger->log($user, PersonalAction::LOGIN, [], 999999);

        // No exception was thrown (we reached this assertion) and nothing persisted.
        $this->assertFalse(
            PersonalActivityLog::where('user_id', $user->id)->exists(),
            'No log row should be persisted when the write throws'
        );
    }
}
