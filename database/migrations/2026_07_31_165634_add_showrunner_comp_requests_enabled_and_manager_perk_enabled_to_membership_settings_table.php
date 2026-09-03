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
        // Defaulted true so upgrading an existing install never silently
        // disables a feature already in use; a fresh install turns off what
        // it doesn't need on the Feature Flags page. See the commit history.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('showrunner_comp_requests_enabled')->default(true)->after('add_ons_enabled');
            $table->boolean('manager_perk_enabled')->default(true)->after('showrunner_comp_requests_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['showrunner_comp_requests_enabled', 'manager_perk_enabled']);
        });
    }
};
