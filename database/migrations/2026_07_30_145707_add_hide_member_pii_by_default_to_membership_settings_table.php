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
        // Defaults true for both a fresh install and the existing singleton
        // row on an upgrade -- hidden-by-default is the house policy going
        // forward; a Manager+ can flip it off here if a location prefers the
        // old always-shown behavior.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('hide_member_pii_by_default')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('hide_member_pii_by_default');
        });
    }
};
