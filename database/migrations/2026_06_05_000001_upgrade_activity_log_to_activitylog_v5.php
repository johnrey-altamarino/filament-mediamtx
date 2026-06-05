<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-activitylog v5 schema changes:
 *  - adds `attribute_changes` (tracked model changes moved out of `properties`)
 *  - drops `batch_uuid` (batch system removed in v5)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->json('attribute_changes')->nullable()->after('properties');
        });

        // Move the tracked-change keys out of `properties` into `attribute_changes`.
        DB::table('activity_log')->whereNotNull('properties')->eachById(function ($row) {
            $properties = json_decode($row->properties ?? '', true) ?: [];
            $changes = array_intersect_key($properties, array_flip(['attributes', 'old']));
            $remaining = array_diff_key($properties, array_flip(['attributes', 'old']));

            DB::table('activity_log')->where('id', $row->id)->update([
                'attribute_changes' => empty($changes) ? null : json_encode($changes),
                'properties' => empty($remaining) ? null : json_encode($remaining),
            ]);
        });

        if (Schema::hasColumn('activity_log', 'batch_uuid')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->dropColumn('batch_uuid');
            });
        }
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->uuid('batch_uuid')->nullable()->after('properties');
        });

        // Fold tracked changes back into `properties` before dropping the column.
        DB::table('activity_log')->whereNotNull('attribute_changes')->eachById(function ($row) {
            $properties = json_decode($row->properties ?? '', true) ?: [];
            $changes = json_decode($row->attribute_changes ?? '', true) ?: [];

            DB::table('activity_log')->where('id', $row->id)->update([
                'properties' => json_encode(array_merge($properties, $changes)),
            ]);
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn('attribute_changes');
        });
    }
};
