<?php

use App\Models\Attendance;
use App\Models\RegisterShift;
use App\Models\Subscription;
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
        // Append-only record of each same-night "convert entry to
        // subscription" correction -- an exception to standard procedure, so
        // who/why/how much is kept for good. Written only by
        // App\Services\EntryCorrectionService. net_amount is signed: positive
        // was collected, negative was refunded, both on register_shift_id
        // with payment_method.
        Schema::create('payment_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Attendance::class)->constrained();
            $table->foreignIdFor(Subscription::class)->constrained();
            $table->foreignIdFor(RegisterShift::class)->nullable()->constrained();
            $table->string('payment_method', 30)->nullable();
            $table->decimal('old_amount_paid', 8, 2);
            $table->decimal('new_amount_paid', 8, 2);
            $table->decimal('subscription_amount', 8, 2);
            $table->decimal('net_amount', 8, 2);
            $table->string('reason', 255);
            $table->foreignIdFor(User::class, 'corrected_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_corrections');
    }
};
