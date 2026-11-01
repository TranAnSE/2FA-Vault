<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up() : void
    {
        Schema::create('twofaccount_group_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('twofaccount_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('group_id');
            $table->timestamps();

            // unsignedInteger FKs on purpose: legacy groups.id and
            // twofaccounts.id are INT columns — foreignId() would create
            // BIGINT columns and MySQL rejects the FK with errno 3780.
            $table->foreign('twofaccount_id')->references('id')->on('twofaccounts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();

            $table->unique(['twofaccount_id', 'user_id'], 'twofaccount_group_assignments_unique_scope');
            $table->index('group_id');
            $table->index('user_id');
        });

        $this->backfillLegacyAssignments();
    }

    /**
     * Reverse the migrations.
     */
    public function down() : void
    {
        Schema::dropIfExists('twofaccount_group_assignments');
    }

    /**
     * Transpose the legacy scalar twofaccounts.group_id into per-user pivot
     * rows. Fork-native and engine-safe: chunked, insertOrIgnore (idempotent
     * re-runs), and joins the account owner to the group owner so an account
     * can only be transposed into a group its owner actually owns.
     */
    public function backfillLegacyAssignments() : void
    {
        if (! Schema::hasColumn('twofaccounts', 'group_id')) {
            return;
        }

        $transposed = 0;

        DB::table('twofaccounts as t')
            ->join('groups as g', 'g.id', '=', 't.group_id')
            ->whereNotNull('t.group_id')
            ->whereNotNull('t.user_id')
            ->whereColumn('g.user_id', 't.user_id')
            ->select([
                't.id as twofaccount_id',
                't.user_id',
                't.group_id',
            ])
            ->orderBy('t.id')
            ->chunk(500, function ($rows) use (&$transposed) {
                $now = now();

                $payload = $rows->map(fn ($row) => [
                    'twofaccount_id' => $row->twofaccount_id,
                    'user_id'        => $row->user_id,
                    'group_id'       => $row->group_id,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ])->all();

                // insertOrIgnore keeps the transpose idempotent (unique scope
                // on [twofaccount_id, user_id]) and skips orphaned pairs.
                $transposed += DB::table('twofaccount_group_assignments')->insertOrIgnore($payload);
            });

        Log::info(sprintf('Group memberships transposed to pivot: %d rows', $transposed));
    }
};
