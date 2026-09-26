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
        // How many guests one member may register in a night -- counted from
        // when they checked in tonight, so an event running past midnight
        // doesn't reset it. Null means no limit, which is what the desk
        // always did, so upgrading changes nothing. See
        // Member::hasGuestAllowanceLeft().
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_guests_per_night')->nullable()->after('guests_allowed_during_probation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('max_guests_per_night');
        });
    }
};
