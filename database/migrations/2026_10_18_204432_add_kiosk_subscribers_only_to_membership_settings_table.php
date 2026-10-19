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
        // Whether the kiosk admits only members with an active subscription
        // (what it always did), or anyone the desk would admit for $0 -- a
        // comped category, the event's host. Default true keeps an
        // upgrading install's kiosk exactly as it was.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('kiosk_subscribers_only')->default(true)->after('kiosk_show_code_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('kiosk_subscribers_only');
        });
    }
};
