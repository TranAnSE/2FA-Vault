<?php

namespace Tests\Feature\Services;

use App\Enums\TeamAction;
use App\Models\Team;
use App\Models\TeamActivityLog;
use App\Models\TwoFAccount;
use App\Models\User;
use App\Services\TeamActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TeamActivityLogger Service Tests
 *
 * Tests for the team activity logging service layer.
 */
class TeamActivityLoggerTest extends TestCase
{
    use RefreshDatabase;

    private TeamActivityLogger $logger;
    private Team $team;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new TeamActivityLogger();
        $this->actor = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $this->actor->id]);
        $this->team->users()->attach($this->actor->id, ['role' => 'owner', 'joined_at' => now()]);
    }

    /**
     * Test log creates entry with correct action.
     */
    public function test_log_creates_entry_with_correct_action(): void
    {
        $this->logger->log($this->team, $this->actor, TeamAction::TEAM_CREATED);

        $this->assertDatabaseHas('team_activity_logs', [
            'team_id' => $this->team->id,
            'user_id' => $this->actor->id,
            'action'  => 'team.created',
        ]);
    }

    /**
     * Test log stores metadata as JSON.
     */
    public function test_log_stores_metadata_json(): void
    {
        $metadata = ['old_name' => 'Alpha', 'new_name' => 'Beta'];

        $this->logger->log($this->team, $this->actor, TeamAction::TEAM_UPDATED, $metadata);

        $log = TeamActivityLog::first();
        $this->assertNotNull($log);
        $this->assertEquals($metadata, $log->metadata);
    }

    /**
     * Test log records correct user and team IDs.
     */
    public function test_log_records_user_and_team_id(): void
    {
        $this->logger->log($this->team, $this->actor, TeamAction::MEMBER_LEFT);

        $log = TeamActivityLog::first();
        $this->assertEquals($this->team->id, $log->team_id);
        $this->assertEquals($this->actor->id, $log->user_id);
    }

    /**
     * Test log stores target user and target account.
     */
    public function test_log_stores_target_user_and_account(): void
    {
        $targetUser = User::factory()->create();
        $targetAccount = TwoFAccount::factory()->create(['user_id' => $this->actor->id]);

        $this->logger->log(
            $this->team,
            $this->actor,
            TeamAction::ACCOUNT_SHARED,
            null,
            $targetUser,
            $targetAccount,
        );

        $log = TeamActivityLog::first();
        $this->assertEquals($targetUser->id, $log->target_user_id);
        $this->assertEquals($targetAccount->id, $log->target_account_id);
    }

    /**
     * Test all TeamAction enum cases produce valid entries.
     */
    public function test_all_team_action_enum_cases_produce_valid_entries(): void
    {
        foreach (TeamAction::cases() as $action) {
            $this->logger->log($this->team, $this->actor, $action);
        }

        $logs = TeamActivityLog::pluck('action')->toArray();
        $this->assertCount(count(TeamAction::cases()), $logs);

        foreach (TeamAction::cases() as $action) {
            $this->assertContains($action->value, $logs, "Missing log entry for action: {$action->value}");
        }
    }

    /**
     * Test log failure does not throw exception (fire-and-forget).
     */
    public function test_log_failure_does_not_throw(): void
    {
        // Create a team with a non-existent ID to force a constraint violation
        $phantomTeam = new Team();
        $phantomTeam->id = 999999;
        $phantomTeam->exists = false;

        // Should NOT throw — logger swallows failures silently
        $this->logger->log($phantomTeam, $this->actor, TeamAction::TEAM_DELETED);

        // No entry should exist since the team_id FK constraint fails
        $this->assertEquals(0, TeamActivityLog::count());
    }

    /**
     * Test activity logs are sorted by created_at descending via relationship.
     */
    public function test_activity_logs_sorted_by_created_at_desc(): void
    {
        // Create entries with explicit created_at to control ordering
        TeamActivityLog::create([
            'team_id'    => $this->team->id,
            'user_id'    => $this->actor->id,
            'action'     => 'team.created',
            'created_at' => now()->subDays(2),
        ]);
        TeamActivityLog::create([
            'team_id'    => $this->team->id,
            'user_id'    => $this->actor->id,
            'action'     => 'member.joined',
            'created_at' => now()->subDay(),
        ]);
        TeamActivityLog::create([
            'team_id'    => $this->team->id,
            'user_id'    => $this->actor->id,
            'action'     => 'account.shared',
            'created_at' => now(),
        ]);

        $logs = $this->team->activityLogs()->orderBy('created_at', 'desc')->get();

        $this->assertEquals('account.shared', $logs[0]->action);
        $this->assertEquals('member.joined', $logs[1]->action);
        $this->assertEquals('team.created', $logs[2]->action);
    }

    /**
     * Test get activity logs returns entries for the team only.
     */
    public function test_get_activity_logs_returns_team_entries_only(): void
    {
        $otherOwner = User::factory()->create();
        $otherTeam = Team::factory()->create(['owner_id' => $otherOwner->id]);
        $otherTeam->users()->attach($otherOwner->id, ['role' => 'owner', 'joined_at' => now()]);

        // Log for both teams
        $this->logger->log($this->team, $this->actor, TeamAction::TEAM_CREATED);
        $this->logger->log($this->team, $this->actor, TeamAction::MEMBER_INVITED);
        $this->logger->log($otherTeam, $otherOwner, TeamAction::TEAM_CREATED);

        // Only this team's entries
        $this->team->refresh();
        $logs = $this->team->activityLogs()->get();

        $this->assertCount(2, $logs);
        $logs->each(fn ($log) => $this->assertEquals($this->team->id, $log->team_id));
    }
}
