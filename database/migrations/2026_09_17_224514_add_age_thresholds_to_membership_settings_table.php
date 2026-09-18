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
        // Both ages were bare literals inside AdmissionPolicy::decide() --
        // jurisdiction-specific concepts (age of majority isn't 18
        // everywhere; 21 is a US drinking-age convention) hardcoded into a
        // public OSS project. Defaulted to the app's original hardcoded
        // values so upgrading changes nothing.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->unsignedInteger('age_of_majority')->default(18)->after('event_window_buffer_minutes');
            $table->unsignedInteger('alcohol_flag_age')->default(21)->after('age_of_majority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['age_of_majority', 'alcohol_flag_age']);
        });
    }
};
