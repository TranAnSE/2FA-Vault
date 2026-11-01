<?php

namespace Tests;

use App\Models\User;
use App\Models\TwoFAccount;
use App\Models\Group;
use App\Facades\Groups;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class FeatureTestCase extends BaseTestCase
{
    /**
     * Create a TwoFAccount and assign it to the provided group for the owner
     * (many-to-many pivot row since the phase-05 migration).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createTwofaccountInGroup(User $owner, Group $group, array $attributes = []) : TwoFAccount
    {
        unset($attributes['group_id']);

        $twofaccount = TwoFAccount::factory()
            ->for($owner)
            ->create($attributes);

        Groups::assign($twofaccount->id, $owner, $group);

        return $twofaccount->refresh();
    }

    use CreatesApplication;

    /**
     * Refresh db only when test is using database transaction.
     */
    use LazilyRefreshDatabase;

    /**
     * Perform any work that should take place once the database has finished refreshing.
     *
     * @return void
     */
    protected function afterRefreshingDatabase()
    {
        //
    }
}
