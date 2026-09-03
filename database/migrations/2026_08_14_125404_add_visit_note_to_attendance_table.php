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
        // A mutable, single-value description (e.g. clothing) so staff can
        // spot a flagged patron in a crowd tonight -- distinct from
        // attendance_behavior_notes below, which is append-only and
        // permanent. Deliberately never surfaced on any historical view
        // (AttendanceRelationManager, etc.) -- once this row drops off
        // ActivePatrons (event ends / departed), nothing in the UI shows it
        // again, even though the column itself is never cleared or deleted.
        Schema::table('attendance', function (Blueprint $table) {
            $table->string('visit_note', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn('visit_note');
        });
    }
};
