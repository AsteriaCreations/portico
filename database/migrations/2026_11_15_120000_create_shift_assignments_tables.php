<?php

use App\Models\ShiftSlot;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff scheduling, part three: who is scheduled on each shift, and
     * which schedule weeks have been published to staff (keyed on the
     * week's first day, MembershipSetting::startOfScheduleWeek()). A week
     * with no row hasn't been published.
     */
    public function up(): void
    {
        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ShiftSlot::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamps();
            $table->unique(['shift_slot_id', 'user_id']);
        });

        Schema::create('schedule_weeks', function (Blueprint $table) {
            $table->id();
            $table->date('week_starts_on')->unique();
            $table->dateTime('published_at');
            $table->foreignId('published_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_weeks');
        Schema::dropIfExists('shift_assignments');
    }
};
