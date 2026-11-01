<?php

namespace Tests\Unit;

use App\Events\GroupDeleted;
use App\Models\Group;
use App\Models\TwoFAccount;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\ModelTestCase;

/**
 * GroupModelTest test class
 */
#[CoversClass(Group::class)]
class GroupModelTest extends ModelTestCase
{
    #[Test]
    public function test_model_configuration()
    {
        $this->runConfigurationAssertions(
            new Group,
            ['name', 'show_in_chips'],
            ['created_at', 'updated_at'],
            ['*'],
            [],
            [
                'id'                 => 'int',
                'twofaccounts_count' => 'integer',
                'user_id'            => 'integer',
                'show_in_chips'      => 'boolean',
            ],
            [
                'deleted' => GroupDeleted::class,
            ]
        );
    }

    #[Test]
    public function test_twofaccounts_relation()
    {
        $group    = new Group;
        $accounts = $group->twofaccounts();

        // m2m: the inverse side runs through the assignments pivot
        $this->assertInstanceOf(BelongsToMany::class, $accounts);
        $this->assertSame('twofaccount_group_assignments', $accounts->getTable());
        $this->assertSame('group_id', $accounts->getForeignPivotKeyName());
        $this->assertSame('twofaccount_id', $accounts->getRelatedPivotKeyName());
    }

    #[Test]
    public function test_user_relation()
    {
        $model    = new Group;
        $relation = $model->user();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertEquals('user_id', $relation->getForeignKeyName());
    }
}
