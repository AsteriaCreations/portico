<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rooms a member can book for a private rental at the desk. Pricing is
     * club data, never constants: an hourly rate for the room, a number of
     * people it includes free, and a per-person fee for everyone past that,
     * charged either per hour of the booking or once (overage_per_hour).
     * Bookings snapshot these values, so editing a room never changes one
     * already made. Deactivate a room rather than deleting it.
     */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('description')->nullable();
            $table->decimal('hourly_rate', 8, 2);
            $table->unsignedSmallInteger('included_people')->default(0);
            $table->decimal('overage_fee', 8, 2)->default(0);
            $table->boolean('overage_per_hour')->default(true);
            $table->unsignedTinyInteger('min_hours')->default(1);
            $table->unsignedTinyInteger('max_hours')->default(8);
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
