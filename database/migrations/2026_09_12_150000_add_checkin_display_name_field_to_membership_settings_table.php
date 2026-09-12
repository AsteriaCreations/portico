<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Which member field the Check-In Desk shows once a member is
        // selected -- the greeting line, the checked-in roster, the voucher
        // labels, and the guest-registration sponsor note. One of
        // 'preferred_name' / 'full_name' / 'username'. See
        // Member::displayName() and App\Filament\Admin\Pages\MembershipSettings.
        // A distinct concern from member_search_fields (what staff can
        // search on) even though it lives on the same settings page.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->string('checkin_display_name_field')->nullable()->after('member_search_fields');
        });

        // Explicit on the existing row rather than leaning on the
        // null-coalesce in code -- same reasoning as member_search_fields's
        // own migration -- so the settings page renders "Preferred name"
        // already selected, matching how the desk actually behaved before
        // this setting existed.
        DB::table('membership_settings')
            ->whereNull('checkin_display_name_field')
            ->update(['checkin_display_name_field' => 'preferred_name']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('checkin_display_name_field');
        });
    }
};
