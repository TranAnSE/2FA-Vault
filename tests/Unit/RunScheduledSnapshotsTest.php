<?php

namespace Tests\Unit;

use App\Console\Commands\RunScheduledSnapshots;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * RunScheduledSnapshots due-check tests (mirrors RunAutoBackupsCommandTest).
 * The command itself is scheduler-driven (@codeCoverageIgnore); the due
 * matrix is what matters: off/daily/weekly, time gating, C12 catch-up.
 */
class RunScheduledSnapshotsTest extends TestCase
{
    private function at(string $utc): Carbon
    {
        return Carbon::parse($utc, 'UTC');
    }

    public function test_off_frequency_is_never_due(): void
    {
        $this->assertFalse(RunScheduledSnapshots::isSnapshotDue(['snapshot_frequency' => 'off'], $this->at('2026-10-09 10:00'), null));
        $this->assertFalse(RunScheduledSnapshots::isSnapshotDue([], $this->at('2026-10-09 10:00'), null));
    }

    public function test_not_due_before_configured_time(): void
    {
        $prefs = ['snapshot_frequency' => 'daily', 'snapshot_time' => '02:00'];
        $this->assertFalse(RunScheduledSnapshots::isSnapshotDue($prefs, $this->at('2026-10-09 01:59'), null));
        $this->assertTrue(RunScheduledSnapshots::isSnapshotDue($prefs, $this->at('2026-10-09 02:00'), null));
        $this->assertTrue(RunScheduledSnapshots::isSnapshotDue($prefs, $this->at('2026-10-09 23:59'), null));
    }

    public function test_first_run_is_due_once_time_reached(): void
    {
        $prefs = ['snapshot_frequency' => 'daily', 'snapshot_time' => '02:00'];
        $this->assertTrue(RunScheduledSnapshots::isSnapshotDue($prefs, $this->at('2026-10-09 05:00'), null));
    }

    public function test_daily_not_due_again_same_day(): void
    {
        $prefs = ['snapshot_frequency' => 'daily', 'snapshot_time' => '02:00'];
        // Last run at 02:00 today; dueAt (today 02:00) is NOT after lastRun.
        $this->assertFalse(RunScheduledSnapshots::isSnapshotDue($prefs, $this->at('2026-10-09 10:00'), $this->at('2026-10-09 02:00')));
        // Last run yesterday: due again (catch-up semantics).
        $this->assertTrue(RunScheduledSnapshots::isSnapshotDue($prefs, $this->at('2026-10-09 10:00'), $this->at('2026-10-08 05:00')));
    }

    public function test_weekly_cadence(): void
    {
        $prefs = ['snapshot_frequency' => 'weekly', 'snapshot_time' => '02:00'];
        // Ran yesterday → not due for another ~6 days.
        $this->assertFalse(RunScheduledSnapshots::isSnapshotDue($prefs, $this->at('2026-10-09 10:00'), $this->at('2026-10-08 05:00')));
        // Ran 8 days ago → due (catch-up).
        $this->assertTrue(RunScheduledSnapshots::isSnapshotDue($prefs, $this->at('2026-10-09 10:00'), $this->at('2026-10-01 05:00')));
    }

    public function test_default_time_is_used_when_preference_missing(): void
    {
        // No snapshot_time → 02:00 UTC default.
        $this->assertFalse(RunScheduledSnapshots::isSnapshotDue(['snapshot_frequency' => 'daily'], $this->at('2026-10-09 01:00'), null));
        $this->assertTrue(RunScheduledSnapshots::isSnapshotDue(['snapshot_frequency' => 'daily'], $this->at('2026-10-09 02:00'), null));
    }

    public function test_unknown_frequency_fails_closed(): void
    {
        $this->assertFalse(RunScheduledSnapshots::isSnapshotDue(['snapshot_frequency' => 'hourly'], $this->at('2026-10-09 10:00'), null));
    }
}
