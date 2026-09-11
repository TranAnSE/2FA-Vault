<?php

namespace Tests\Feature\Policy;

use App\Models\TwoFAccount;
use App\Models\User;
use App\Policies\GroupPolicy;
use App\Policies\SecureNotePolicy;
use App\Policies\TwoFAccountPolicy;
use App\Policies\UserPolicy;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * Regression tests for the removal of the global admin Gate::before bypass
 * (upstream v8 hardening, commit 54e8fc0fb).
 *
 * Semantics after removal:
 * - Administrators are NOT implicitly allowed by ownership/membership
 *   policies (TwoFAccount, Group, SecureNote, Team).
 * - Administrator capabilities come from route middleware (AdminOnly) and
 *   explicit policy allowances (UserPolicy::before).
 */
#[CoversMethod(AppServiceProvider::class, 'boot')]
#[CoversClass(TwoFAccountPolicy::class)]
#[CoversClass(GroupPolicy::class)]
#[CoversClass(SecureNotePolicy::class)]
#[CoversClass(UserPolicy::class)]
class AdminGateBypassRemovalTest extends FeatureTestCase
{
    /**
     * @var \App\Models\User|\Illuminate\Contracts\Auth\Authenticatable
     */
    protected $admin;

    /**
     * @var \App\Models\User|\Illuminate\Contracts\Auth\Authenticatable
     */
    protected $user;

    protected function setUp() : void
    {
        parent::setUp();

        $this->admin = User::factory()->administrator()->create();
        $this->user  = User::factory()->create();
    }

    #[Test]
    public function test_admin_is_not_implicitly_allowed_to_view_another_users_twofaccount()
    {
        $twofaccount = TwoFAccount::factory()->for($this->user)->create();

        $this->assertFalse(
            Gate::forUser($this->admin)->allows('view', $twofaccount),
            'TwoFAccountPolicy::view must deny non-owners, including administrators'
        );
    }

    #[Test]
    public function test_admin_is_not_implicitly_allowed_to_update_or_delete_another_users_twofaccount()
    {
        $twofaccount = TwoFAccount::factory()->for($this->user)->create();

        $gate = Gate::forUser($this->admin);

        $this->assertFalse($gate->allows('update', $twofaccount));
        $this->assertFalse($gate->allows('delete', $twofaccount));
        $this->assertFalse($gate->allows('readSecret', $twofaccount));
        $this->assertFalse($gate->allows('generateOtp', $twofaccount));
        $this->assertFalse($gate->allows('transferOwnership', $twofaccount));
    }

    #[Test]
    public function test_owner_still_allowed_on_own_twofaccount()
    {
        $twofaccount = TwoFAccount::factory()->for($this->user)->create();

        $gate = Gate::forUser($this->user);

        $this->assertTrue($gate->allows('view', $twofaccount));
        $this->assertTrue($gate->allows('update', $twofaccount));
        $this->assertTrue($gate->allows('delete', $twofaccount));
        $this->assertTrue($gate->allows('readSecret', $twofaccount));
    }

    #[Test]
    public function test_admin_is_not_implicitly_allowed_on_others_groups_and_secure_notes()
    {
        $group = \App\Models\Group::factory()->for($this->user)->create();
        $note  = \App\Models\SecureNote::factory()->for($this->user)->create();

        $gate = Gate::forUser($this->admin);

        $this->assertFalse($gate->allows('update', $group));
        $this->assertFalse($gate->allows('delete', $group));
        $this->assertFalse($gate->allows('view', $note));
        $this->assertFalse($gate->allows('update', $note));
        $this->assertFalse($gate->allows('delete', $note));
    }

    #[Test]
    public function test_non_admin_is_denied_admin_user_management_abilities()
    {
        $another = User::factory()->create();

        $gate = Gate::forUser($this->user);

        // UserPolicy only allows self-management for regular users.
        $this->assertFalse($gate->allows('view', $another));
        $this->assertFalse($gate->allows('update', $another));
        $this->assertFalse($gate->allows('delete', $another));
    }

    #[Test]
    public function test_admin_still_allowed_on_other_users_via_explicit_user_policy_before()
    {
        $another = User::factory()->create();

        $gate = Gate::forUser($this->admin);

        // UserPolicy carries its own explicit isAdministrator() allowance.
        $this->assertTrue($gate->allows('view', $another));
        $this->assertTrue($gate->allows('update', $another));
        $this->assertTrue($gate->allows('delete', $another));
    }

    #[Test]
    public function test_admin_api_routes_still_work_via_admin_middleware()
    {
        Passport::actingAs($this->admin, ['legacy_full_access'], 'api-guard');

        $response = $this->getJson('/api/v1/users');

        $response->assertStatus(200);
    }

    #[Test]
    public function test_non_admin_is_forbidden_on_admin_api_routes()
    {
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        $this->getJson('/api/v1/users')->assertForbidden();
    }
}
