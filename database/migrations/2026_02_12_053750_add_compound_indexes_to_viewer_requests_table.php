<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function hasIndex(string $table, string $indexName): bool
    {
        // SQLite does not have information_schema; use pragma instead
        if (DB::getDriverName() !== 'mysql') {
            $indexes = DB::select("PRAGMA index_list(\"{$table}\")");
            foreach ($indexes as $index) {
                if ($index->name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $db = DB::getDatabaseName();

        $result = DB::selectOne('
            SELECT COUNT(1) AS c
            FROM information_schema.statistics
            WHERE table_schema = ?
              AND table_name = ?
              AND index_name = ?
        ', [$db, $table, $indexName]);

        return (int) ($result->c ?? 0) > 0;
    }

    public function up(): void
    {
        Schema::table('viewer_requests', function (Blueprint $table) {
            // add columns indexes conditionally (handled below)
        });

        // Compound indexes (skip if already exists)
        if (! $this->hasIndex('viewer_requests', 'vr_allowed_requested_at_idx')) {
            Schema::table('viewer_requests', function (Blueprint $table) {
                $table->index(['allowed', 'requested_at'], 'vr_allowed_requested_at_idx');
            });
        }

        if (! $this->hasIndex('viewer_requests', 'vr_requested_at_ip_idx')) {
            Schema::table('viewer_requests', function (Blueprint $table) {
                $table->index(['requested_at', 'ip'], 'vr_requested_at_ip_idx');
            });
        }

        if (! $this->hasIndex('viewer_requests', 'vr_requested_at_path_idx')) {
            Schema::table('viewer_requests', function (Blueprint $table) {
                $table->index(['requested_at', 'path'], 'vr_requested_at_path_idx');
            });
        }

        if (! $this->hasIndex('viewer_requests', 'vr_deny_reason_requested_at_idx')) {
            Schema::table('viewer_requests', function (Blueprint $table) {
                $table->index(['deny_reason', 'requested_at'], 'vr_deny_reason_requested_at_idx');
            });
        }

        // Prefix index for TEXT user_agent (MySQL only)
        if (DB::getDriverName() === 'mysql' && ! $this->hasIndex('viewer_requests', 'vr_user_agent_prefix_idx')) {
            DB::statement('CREATE INDEX vr_user_agent_prefix_idx ON viewer_requests (user_agent(120))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && $this->hasIndex('viewer_requests', 'vr_user_agent_prefix_idx')) {
            DB::statement('DROP INDEX vr_user_agent_prefix_idx ON viewer_requests');
        }

        Schema::table('viewer_requests', function (Blueprint $table) {
            // Drop only if they exist (safe to attempt with try/catch is another approach)
        });

        foreach ([
            'vr_allowed_requested_at_idx',
            'vr_requested_at_ip_idx',
            'vr_requested_at_path_idx',
            'vr_deny_reason_requested_at_idx',
        ] as $idx) {
            if ($this->hasIndex('viewer_requests', $idx)) {
                Schema::table('viewer_requests', function (Blueprint $table) use ($idx) {
                    $table->dropIndex($idx);
                });
            }
        }
    }
};
