<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            // Fast filtering in Filament
            $table->index(['log_name', 'created_at'], 'al_logname_created_at_idx');
            $table->index('event', 'al_event_idx');

            // Typical queries: "who did what" / "what happened to X"
            $table->index(['causer_type', 'causer_id'], 'al_causer_idx');
            $table->index(['subject_type', 'subject_id'], 'al_subject_idx');

            // Time range scans
            $table->index('created_at', 'al_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('al_logname_created_at_idx');
            $table->dropIndex('al_event_idx');
            $table->dropIndex('al_causer_idx');
            $table->dropIndex('al_subject_idx');
            $table->dropIndex('al_created_at_idx');
        });
    }
};
