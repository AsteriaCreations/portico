<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who entered an availability answer or week note: the staff member
     * themselves, or a Manager+ entering it for them (staff who don't use
     * the app yet). Null on rows from before this was recorded.
     */
    public function up(): void
    {
        foreach (['shift_availabilities', 'shift_week_notes'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('entered_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['shift_availabilities', 'shift_week_notes'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('entered_by');
            });
        }
    }
};
