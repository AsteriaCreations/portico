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
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('showrunner_payouts_enabled')->default(true);
            $table->boolean('instructor_payouts_enabled')->default(true);
            $table->boolean('showrunner_door_includes_pool')->default(false);
            $table->boolean('showrunner_door_includes_addons')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn([
                'showrunner_payouts_enabled',
                'instructor_payouts_enabled',
                'showrunner_door_includes_pool',
                'showrunner_door_includes_addons',
            ]);
        });
    }
};
