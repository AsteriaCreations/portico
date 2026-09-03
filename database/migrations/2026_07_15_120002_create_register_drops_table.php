<?php

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
        // Append-only ledger of cash removed from the box mid-shift or at
        // close — mirrors vouchers/occupancy_adjustments exactly (no
        // updated_at; a correction is a new row, never an edit).
        // RegisterShiftService::totalDrops() sums these against a shift.
        Schema::create('register_drops', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(RegisterShift::class)->constrained();
            $table->decimal('amount', 8, 2); // positive = removed from the box
            $table->string('reason', 255)->nullable();
            $table->foreignIdFor(User::class, 'recorded_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('register_drops');
    }
};
