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
        // Cash (or other payment) taken at the register for something that
        // never touches Attendance/Subscription at all -- a vendor payment,
        // a private rental, a donation. Append-only, mirrors register_drops/
        // vouchers/occupancy_adjustments exactly (no updated_at; a correction
        // is a new row, never an edit). notation is free text rather than a
        // fixed reason enum, since these are inherently one-off and don't fit
        // a picklist. RegisterShiftService::cashReceived() folds a
        // requires_register_shift-flagged row into box reconciliation the
        // same way it already does attendance/subscriptions cash rows.
        Schema::create('miscellaneous_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(RegisterShift::class)->constrained();
            $table->string('payment_method', 30);
            $table->decimal('amount', 8, 2);
            $table->string('notation', 255);
            $table->foreignIdFor(User::class, 'recorded_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('miscellaneous_payments');
    }
};
