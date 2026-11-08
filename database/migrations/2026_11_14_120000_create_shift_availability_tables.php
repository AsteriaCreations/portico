<?php

use App\Models\ShiftSlot;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff scheduling, part two: what each staff member says about each
     * shift (preferred / available / no; no row = not answered yet), and an
     * optional note for the whole schedule week, keyed on the week's first
     * day (MembershipSetting::startOfScheduleWeek()).
     */
    public function up(): void
    {
        Schema::create('shift_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ShiftSlot::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->enum('preference', ['preferred', 'available', 'unavailable']);
            $table->timestamps();
            $table->unique(['shift_slot_id', 'user_id']);
        });

        Schema::create('shift_week_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->date('week_starts_on');
            $table->string('note', 500);
            $table->timestamps();
            $table->unique(['user_id', 'week_starts_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_week_notes');
        Schema::dropIfExists('shift_availabilities');
    }
};
