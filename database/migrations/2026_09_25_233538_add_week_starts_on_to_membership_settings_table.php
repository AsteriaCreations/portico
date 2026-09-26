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
        // Which day the club's week starts on, as a Carbon day number
        // (0 = Sunday … 6 = Saturday), for the weekly Analytics figures and
        // the Cleaning Checklist reset. Null follows the install's language
        // (Monday in English), which is what they always did, so upgrading
        // changes nothing. See MembershipSetting::startOfWeek().
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('week_starts_on')->nullable()->after('event_window_buffer_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('week_starts_on');
        });
    }
};
