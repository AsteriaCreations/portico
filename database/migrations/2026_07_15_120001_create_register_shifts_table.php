<?php

use App\Models\Register;
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
        // One row per shift on a register, mutated once when it closes — the
        // same open-then-decided lifecycle shape as comp_requests
        // (pending -> approved/rejected), not an append-only ledger like
        // vouchers/occupancy_adjustments/register_drops. created_at doubles as
        // "opened at"; closed_at is the only other lifecycle timestamp needed.
        // One open shift per register at a time is enforced in
        // RegisterShiftService, not a DB constraint.
        Schema::create('register_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Register::class)->constrained();
            $table->foreignIdFor(User::class, 'opened_by')->constrained('users');
            $table->decimal('opening_count', 8, 2);
            $table->foreignIdFor(User::class, 'closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('closing_count', 8, 2)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['register_id', 'closed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('register_shifts');
    }
};
