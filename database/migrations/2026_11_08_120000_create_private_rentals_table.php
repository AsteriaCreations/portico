<?php

use App\Models\Event;
use App\Models\Member;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A room booked for a member's private rental at the desk. Each booking
     * owns one event (the renter is its host), which is what the desk checks
     * the group in to. The room's prices are snapshotted here when booked,
     * so a later change to the room never moves a booking's total. Overlap
     * is judged on starts_at/ends_at among bookings that aren't cancelled
     * (PrivateRentalService::conflictFor()).
     */
    public function up(): void
    {
        Schema::create('private_rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Room::class)->constrained();
            $table->foreignIdFor(Member::class)->constrained();
            $table->foreignIdFor(Event::class)->unique()->constrained();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedTinyInteger('hours');
            $table->unsignedSmallInteger('headcount');
            $table->decimal('hourly_rate', 8, 2);
            $table->unsignedSmallInteger('included_people');
            $table->decimal('overage_fee', 8, 2);
            $table->boolean('overage_per_hour');
            $table->decimal('room_cost', 8, 2);
            $table->decimal('extra_people_cost', 8, 2);
            $table->decimal('total', 8, 2);
            $table->foreignIdFor(User::class, 'booked_by')->constrained('users');
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignIdFor(User::class, 'cancelled_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['room_id', 'starts_at'], 'ix_private_rentals_room_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('private_rentals');
    }
};
