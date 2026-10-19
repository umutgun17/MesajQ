<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Add nullable column
        Schema::table('feed_profiles', function (Blueprint $table) {
            $table->string('feed_token', 64)->nullable()->unique()->after('slug');
        });

        // Step 2: Backfill existing rows
        $profiles = DB::table('feed_profiles')->whereNull('feed_token')->get();
        foreach ($profiles as $profile) {
            DB::table('feed_profiles')
                ->where('id', $profile->id)
                ->update(['feed_token' => Str::random(32)]);
        }

        // Step 3: Make NOT NULL
        DB::statement('ALTER TABLE feed_profiles ALTER COLUMN feed_token SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('feed_profiles', function (Blueprint $table) {
            $table->dropColumn('feed_token');
        });
    }
};
