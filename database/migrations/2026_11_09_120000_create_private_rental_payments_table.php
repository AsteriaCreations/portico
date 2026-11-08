<?php

use App\Models\PrivateRental;
use App\Models\RegisterShift;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money taken for a private rental, append-only: a payment is a positive
     * row, a refund a negative one, never an edit. Whether a booking is paid
     * is the sum (PrivateRentalService::netPaidCents()), never a stored
     * flag. amount includes the method's transaction_fee, the way the desk's
     * other sales fold it in. register_shift_id ties the money to the box it
     * went through, so RegisterShiftService counts it as Rental takings.
     */
    public function up(): void
    {
        Schema::create('private_rental_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(PrivateRental::class)->constrained();
            $table->decimal('amount', 8, 2);
            $table->decimal('transaction_fee', 8, 2)->default(0);
            $table->string('payment_method', 30);
            $table->foreignIdFor(RegisterShift::class)->nullable()->constrained();
            $table->string('reason')->nullable();
            $table->foreignIdFor(User::class, 'recorded_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['register_shift_id', 'payment_method'], 'ix_private_rental_payments_shift_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('private_rental_payments');
    }
};
