<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('viewer_requests', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();

            $table->string('ip')->index();
            $table->string('path')->nullable()->index();
            $table->text('referer')->nullable();
            $table->text('user_agent')->nullable();

            $table->string('os')->nullable()->index();
            $table->string('browser')->nullable()->index();
            $table->string('device')->nullable()->index();      // Desktop / Mobile / Tablet / Bot / Unknown
            $table->string('device_model')->nullable();

            $table->boolean('allowed')->default(false)->index();
            $table->string('deny_reason')->nullable();          // blocked_ip / bad_referer / other

            $table->timestamp('requested_at')->useCurrent()->index();
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('viewer_requests');
    }
};
