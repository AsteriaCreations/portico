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
        // Whether Active Patrons' "Also in the building" note shows each
        // signed-in staff member's role next to their name. Defaults true so
        // upgrading changes nothing; a club that would rather not advertise
        // who holds which role turns it off on Membership Settings.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('active_patrons_show_staff_roles')->default(true)->after('hide_member_pii_by_default');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('active_patrons_show_staff_roles');
        });
    }
};
