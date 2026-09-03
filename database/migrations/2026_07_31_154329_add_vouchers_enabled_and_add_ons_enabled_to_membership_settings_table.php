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
        // disables a feature already in use. First pilot of the feature-flag
        // pattern; see docs/BLUEPRINT.md and the commit history.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('vouchers_enabled')->default(true)->after('hide_member_pii_by_default');
            $table->boolean('add_ons_enabled')->default(true)->after('vouchers_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['vouchers_enabled', 'add_ons_enabled']);
        });
    }
};
