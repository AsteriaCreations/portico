<?php

use App\Models\Event;
use App\Models\EventType;
use App\Models\ShiftRole;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff scheduling, part one: the jobs staff work (shift roles, a club's
     * own list, separate from login roles), who can work each one, a
     * per-event-type template of shifts, and the shift slots on each event.
     * A template's start is minutes from the event's start (negative = before
     * doors), so one template fits every event of that type.
     */
    public function up(): void
    {
        Schema::create('shift_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('shift_role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ShiftRole::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->unique(['shift_role_id', 'user_id']);
        });

        Schema::create('shift_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(EventType::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(ShiftRole::class)->constrained()->cascadeOnDelete();
            $table->integer('start_offset_minutes')->default(0);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedTinyInteger('headcount')->default(1);
            $table->timestamps();
        });

        Schema::create('shift_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Event::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(ShiftRole::class)->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedTinyInteger('headcount')->default(1);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['starts_at']);
        });

        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('staff_scheduling_enabled')->default(false)->after('private_rentals_enabled');
            $table->unsignedTinyInteger('staff_schedule_week_starts_on')->nullable()->after('week_starts_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['staff_scheduling_enabled', 'staff_schedule_week_starts_on']);
        });

        Schema::dropIfExists('shift_slots');
        Schema::dropIfExists('shift_templates');
        Schema::dropIfExists('shift_role_user');
        Schema::dropIfExists('shift_roles');
    }
};
