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
        // Defaulted true so upgrading an existing install never silently
        // disables a feature already in use; a fresh install turns off what
        // it doesn't need on the Feature Flags page. See the commit history.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('visit_notes_enabled')->default(true)->after('register_shifts_enabled');
            $table->boolean('behavior_notes_enabled')->default(true)->after('visit_notes_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['visit_notes_enabled', 'behavior_notes_enabled']);
        });
    }
};
