<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $results = DB::select("PRAGMA index_list('{$table}')");
            return collect($results)->contains('name', $indexName);
        }

        // MySQL / PostgreSQL
        $database = Schema::getConnection()->getDatabaseName();
        $results = DB::select(
            "SELECT COUNT(*) as cnt FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?",
            [$database, $table, $indexName]
        );

        return ($results[0]->cnt ?? 0) > 0;
    }

    public function up(): void
    {
        $indexes = [
            'twofaccounts' => [
                'user_id' => 'twofaccounts_user_id_index',
                'order_column' => 'twofaccounts_order_column_index',
                'group_id' => 'twofaccounts_group_id_index',
                'last_used_at' => 'twofaccounts_last_used_at_index',
                'encrypted' => 'twofaccounts_encrypted_index',
            ],
            'groups' => [
                'user_id' => 'groups_user_id_index',
            ],
        ];

        foreach ($indexes as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column => $indexName) {
                    if (!$this->indexExists($table, $indexName)) {
                        $blueprint->index($column, $indexName);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        $indexes = [
            'twofaccounts' => [
                'twofaccounts_user_id_index',
                'twofaccounts_order_column_index',
                'twofaccounts_group_id_index',
                'twofaccounts_last_used_at_index',
                'twofaccounts_encrypted_index',
            ],
            'groups' => [
                'groups_user_id_index',
            ],
        ];

        foreach ($indexes as $table => $indexNames) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $indexNames) {
                foreach ($indexNames as $indexName) {
                    if ($this->indexExists($table, $indexName)) {
                        $blueprint->dropIndex($indexName);
                    }
                }
            });
        }
    }
};
