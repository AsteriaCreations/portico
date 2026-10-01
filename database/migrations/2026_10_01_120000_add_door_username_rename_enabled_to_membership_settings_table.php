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
        // Whether Door staff may rename a member's username from the
        // Check-In Desk. Off by default: renaming was Manager+ only, so
        // upgrading changes nothing until a club opts in.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('door_username_rename_enabled')->default(false)->after('guests_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('door_username_rename_enabled');
        });
    }
};
