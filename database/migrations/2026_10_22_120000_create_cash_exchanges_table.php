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
        // Append-only ledger of cash handed out of the box to someone who
        // paid the same amount electronically (e.g. Venmo for change), like
        // register_drops (no updated_at; a correction is a new row). Not
        // revenue: RegisterShiftService takes amount off expected cash and
        // lists amount + fee as received by payment_method.
        Schema::create('cash_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(RegisterShift::class)->constrained();
            $table->string('payment_method', 30); // a non-cash payment_methods.code
            $table->decimal('amount', 8, 2); // positive = cash out of the box
            $table->decimal('fee', 8, 2)->default(0); // the method's transaction fee at the time, paid on top
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
        Schema::dropIfExists('cash_exchanges');
    }
};
