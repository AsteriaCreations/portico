<?php

use App\Models\RegisterShift;
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
        // Which register shift (if any) was open when this transaction was
        // recorded — a snapshot fact, same spirit as the price-snapshot
        // columns above. Attributed whenever a shift is open regardless of
        // payment_method, since RegisterShiftService::cashReceived() already
        // filters to payment_method = 'cash' itself; attributing every row
        // leaves room for future "total register volume" reporting for free.
        Schema::table('attendance', function (Blueprint $table) {
            $table->foreignIdFor(RegisterShift::class)->nullable()->after('payment_method')->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(RegisterShift::class);
        });
    }
};
