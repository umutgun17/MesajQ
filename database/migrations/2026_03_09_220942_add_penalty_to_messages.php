<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedTinyInteger('penalty_points')->default(0)->after('handling_time_seconds');
            $table->foreignId('penalized_by')->nullable()->after('penalty_points')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['penalized_by']);
            $table->dropColumn(['penalty_points', 'penalized_by']);
        });
    }
};
