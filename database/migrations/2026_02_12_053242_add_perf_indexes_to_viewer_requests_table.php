<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viewer_requests', function (Blueprint $table) {
            // Time filtering
            $table->index('requested_at', 'vr_requested_at_idx');

            // Common dashboard filters
            $table->index(['allowed', 'requested_at'], 'vr_allowed_requested_at_idx');

            // Top IPs / unique viewer style queries
            $table->index(['ip', 'requested_at'], 'vr_ip_requested_at_idx');

            // Stream breakdown queries
            $table->index(['path', 'requested_at'], 'vr_path_requested_at_idx');
        });

        // Prefix index for long text user_agent (MySQL)
        // Works for utf8mb4 safely with a moderate prefix.
        DB::statement('CREATE INDEX vr_user_agent_prefix_idx ON viewer_requests (user_agent(120))');
    }

    public function down(): void
    {
        // Drop prefix index first
        DB::statement('DROP INDEX vr_user_agent_prefix_idx ON viewer_requests');

        Schema::table('viewer_requests', function (Blueprint $table) {
            $table->dropIndex('vr_requested_at_idx');
            $table->dropIndex('vr_allowed_requested_at_idx');
            $table->dropIndex('vr_ip_requested_at_idx');
            $table->dropIndex('vr_path_requested_at_idx');
        });
    }
};
