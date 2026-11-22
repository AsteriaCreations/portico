<?php

use App\Models\Attendance;
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
        // Append-only record of each "Correct payment method" at the desk: a
        // visit recorded as paid one way (say Venmo) when it was really paid
        // another (cash). The visit's payment_method changes and its amount
        // doesn't, so the register's live SUMs move the money between
        // methods. Written only by App\Services\PaymentMethodCorrectionService.
        Schema::create('payment_method_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Attendance::class)->constrained();
            $table->foreignIdFor(RegisterShift::class)->nullable()->constrained();
            $table->string('old_payment_method', 30)->nullable();
            $table->string('new_payment_method', 30);
            $table->decimal('amount', 8, 2);
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
        Schema::dropIfExists('payment_method_corrections');
    }
};
