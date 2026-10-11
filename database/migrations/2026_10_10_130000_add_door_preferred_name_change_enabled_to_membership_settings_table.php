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
        // Whether Door staff may change a member's preferred name from the
        // Check-In Desk. Off by default: it was Manager+ only (the member
        // edit page), so upgrading changes nothing until a club opts in.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('door_preferred_name_change_enabled')->default(false)->after('door_username_rename_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('door_preferred_name_change_enabled');
        });
    }
};
