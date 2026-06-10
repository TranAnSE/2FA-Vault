<?php

namespace Tests\Feature\Services;

use App\Models\Tag;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tag model behavior tests.
 * There is no TagService — the TagController handles all logic directly.
 * This file validates Tag model relationships, constraints, and factory behavior.
 */
class TagModelTest extends TestCase
{
    use RefreshDatabase;

    protected function createEncryptedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'encryption_enabled'   => true,
            'encryption_salt'      => 'test_salt',
            'encryption_test_value' => '{"ciphertext":"test","iv":"test","authTag":"test"}',
            'encryption_version'   => 1,
            'vault_locked'         => false,
        ], $attributes));
    }

    #[Test]
    public function test_can_create_tag_with_name_and_color()
    {
        $user = $this->createEncryptedUser();

        $tag = Tag::factory()->for($user)->create([
            'name'  => 'Work',
            'color' => '#ff0000',
        ]);

        $this->assertDatabaseHas('tags', [
            'id'      => $tag->id,
            'name'    => 'Work',
            'color'   => '#ff0000',
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function test_can_update_tag_name_and_color()
    {
        $user  = $this->createEncryptedUser();
        $tag   = Tag::factory()->for($user)->create(['name' => 'Old', 'color' => '#111111']);

        $tag->update(['name' => 'Updated', 'color' => '#222222']);

        $this->assertDatabaseHas('tags', [
            'id'    => $tag->id,
            'name'  => 'Updated',
            'color' => '#222222',
        ]);
    }

    #[Test]
    public function test_can_delete_tag()
    {
        $user = $this->createEncryptedUser();
        $tag  = Tag::factory()->for($user)->create();

        $tag->delete();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    #[Test]
    public function test_tag_belongs_to_user()
    {
        $user = $this->createEncryptedUser();
        $tag  = Tag::factory()->for($user)->create();

        $this->assertEquals($user->id, $tag->user->id);
        $this->assertInstanceOf(User::class, $tag->user);
    }

    #[Test]
    public function test_tag_can_have_many_accounts()
    {
        $user = $this->createEncryptedUser();
        $tag  = Tag::factory()->for($user)->create();

        $account1 = TwoFAccount::factory()->for($user)->create();
        $account2 = TwoFAccount::factory()->for($user)->create();

        $tag->accounts()->attach([$account1->id, $account2->id]);

        $this->assertCount(2, $tag->fresh()->accounts);
        $this->assertTrue($tag->accounts->contains($account1));
        $this->assertTrue($tag->accounts->contains($account2));
    }

    #[Test]
    public function test_can_remove_tag_from_account()
    {
        $user   = $this->createEncryptedUser();
        $tag    = Tag::factory()->for($user)->create();
        $account = TwoFAccount::factory()->for($user)->create();

        $tag->accounts()->attach($account->id);
        $tag->accounts()->detach($account->id);

        $this->assertCount(0, $tag->fresh()->accounts);
        $this->assertDatabaseMissing('account_tag', [
            'tag_id'        => $tag->id,
            'twofaccount_id' => $account->id,
        ]);
    }

    #[Test]
    public function test_cannot_create_duplicate_tag_name_per_user()
    {
        $user = $this->createEncryptedUser();

        Tag::factory()->for($user)->create(['name' => 'Unique']);

        $this->expectException(QueryException::class);

        Tag::factory()->for($user)->create(['name' => 'Unique']);
    }

    #[Test]
    public function test_get_tags_with_account_count()
    {
        $user = $this->createEncryptedUser();
        $tag  = Tag::factory()->for($user)->create();

        $account1 = TwoFAccount::factory()->for($user)->create();
        $account2 = TwoFAccount::factory()->for($user)->create();

        $tag->accounts()->attach([$account1->id, $account2->id]);

        $tags = $user->tags()->withCount('accounts')->get();

        $this->assertCount(1, $tags);
        $this->assertEquals(2, $tags->first()->accounts_count);
    }
}
