<?php

use App\Models\Attendance;
use App\Models\User;
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
        // Append-only, per-visit behavior notes -- unlike visit_note above,
        // these are permanent (reviewable by Manager+ on the member's
        // profile indefinitely) and individually author-attributed, since
        // visibility differs by who wrote which note (see
        // ActivePatrons::behaviorNotesSummary and AttendanceBehaviorNotePolicy).
        // No edit/delete path for anyone -- written only from ActivePatrons.
        Schema::create('attendance_behavior_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Attendance::class)->constrained();
            $table->text('note');
            $table->foreignIdFor(User::class, 'created_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_behavior_notes');
    }
};
