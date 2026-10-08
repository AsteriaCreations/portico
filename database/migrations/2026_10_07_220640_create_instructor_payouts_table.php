<?php

use App\Models\Event;
use App\Models\RegisterShift;
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
        // Append-only ledger of cash handed to an event's instructor out of
        // the box, like register_drops (no updated_at; a correction is a new
        // row). RegisterShiftService subtracts these from expected cash.
        Schema::create('instructor_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Event::class)->constrained();
            $table->foreignIdFor(RegisterShift::class)->constrained();
            $table->decimal('amount', 8, 2); // positive = paid out of the box
            $table->decimal('calculated_amount', 8, 2); // InstructorPayoutService total at the time
            $table->string('notes', 255)->nullable();
            $table->foreignIdFor(User::class, 'recorded_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instructor_payouts');
    }
};
