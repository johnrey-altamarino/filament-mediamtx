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
    Schema::create('allowed_referrers', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('pattern')->unique(); // regex or exact match
        $table->boolean('is_regex')->default(true);
        $table->boolean('is_enabled')->default(true);

        $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('allowed_referrers');
    }
};
