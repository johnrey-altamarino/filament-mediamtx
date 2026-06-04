<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mediamtx_sessions', function (Blueprint $table) {
            $table->dropColumn(['bytes_received', 'bytes_sent']);
        });
    }

    public function down(): void
    {
        Schema::table('mediamtx_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('bytes_received')->default(0);
            $table->unsignedBigInteger('bytes_sent')->default(0);
        });
    }
};
