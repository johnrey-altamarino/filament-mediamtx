<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mediamtx_sessions', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();

            $table->string('type');          // webrtc/rtmp/rtmps/srt/rtsp/rtsps
            $table->string('session_id');    // mediamtx id
            $table->string('state')->nullable(); // read/publish/...
            $table->string('path')->nullable();
            $table->text('query')->nullable();
            $table->string('remote_addr')->nullable();
            $table->string('ip')->nullable();
            $table->string('created')->nullable();

            $table->unsignedBigInteger('bytes_received')->default(0);
            $table->unsignedBigInteger('bytes_sent')->default(0);

            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();

            $table->unique(['type', 'session_id']);
            $table->index(['last_seen_at']);
            $table->index(['ip']);
            $table->index(['state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mediamtx_sessions');
    }
};
