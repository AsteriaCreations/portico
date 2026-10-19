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
        // Marking a guest's follow-up sent moved them to Irregular. Now a
        // club chooses: move them (default, as before) and to which
        // category -- null meaning Irregular, so a fresh install with no
        // categories yet still behaves the same -- or leave them a Guest.
        // A deleted category falls back to Irregular.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('guest_followup_moves_category')->default(true)->after('max_guests_per_night');
            $table->foreignId('guest_followup_category_id')->nullable()->after('guest_followup_moves_category')->constrained('categories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guest_followup_category_id');
            $table->dropColumn('guest_followup_moves_category');
        });
    }
};
