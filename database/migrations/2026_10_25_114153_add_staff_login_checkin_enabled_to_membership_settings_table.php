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
        // Whether signing in with a login linked to a member checks that
        // member in to tonight's event (StaffLoginCheckInService). Off by
        // default: a new capability, so upgrading changes nothing until a
        // club opts in.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('staff_login_checkin_enabled')->default(false)->after('kiosk_checkin_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('staff_login_checkin_enabled');
        });
    }
};
