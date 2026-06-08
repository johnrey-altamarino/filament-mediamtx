<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mediamtx_sessions', function (Blueprint $table) {
            $table->text('user_agent')->nullable()->after('created');
        });
    }

    public function down(): void
    {
        Schema::table('mediamtx_sessions', function (Blueprint $table) {
            $table->dropColumn('user_agent');
        });
    }
};
