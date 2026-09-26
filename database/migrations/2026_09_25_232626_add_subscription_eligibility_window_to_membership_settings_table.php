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
        // How far back attended events count toward subscription eligibility,
        // in months. Null counts all-time, which is what eligibility always
        // did, so upgrading changes nothing. See Member::eligibilityAttendanceCount().
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('subscription_eligibility_window_months')->nullable()->after('subscription_eligibility_threshold');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('subscription_eligibility_window_months');
        });
    }
};
