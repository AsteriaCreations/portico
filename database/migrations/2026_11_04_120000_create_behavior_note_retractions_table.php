<?php

use App\Models\AttendanceBehaviorNote;
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
        // Append-only: a retracted behavior note keeps its own row (the
        // audit trail of who wrote what); this row records who took it back
        // and why. Written only by App\Services\BehaviorNoteRetractionService.
        // Unique per note -- a note is retracted at most once.
        Schema::create('behavior_note_retractions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(AttendanceBehaviorNote::class)->unique()->constrained();
            $table->string('reason', 255);
            $table->foreignIdFor(User::class, 'retracted_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('behavior_note_retractions');
    }
};
