<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * private_rentals_enabled: a new capability (desk booking of a room for
     * a private rental), so off until a club opts in on Feature Flags.
     * private_rental_guests_allowed: whether the renter's group may include
     * people who aren't members yet, registered as the renter's guests. On
     * by default, matching how guests already work at the desk.
     */
    public function up(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('private_rentals_enabled')->default(false)->after('issue_reports_enabled');
            $table->boolean('private_rental_guests_allowed')->default(true)->after('private_rentals_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['private_rentals_enabled', 'private_rental_guests_allowed']);
        });
    }
};
