<?php

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
        // A workday event: an attendee who stays at least workday_min_hours
        // (checked_in_at to departed_at) earns a workday_voucher_amount
        // voucher once the event ends. Both null = an ordinary event. See
        // vouchers:grant-comp-rewards.
        Schema::table('events', function (Blueprint $table) {
            $table->decimal('workday_voucher_amount', 8, 2)->nullable()->after('pool_fee');
            $table->decimal('workday_min_hours', 4, 2)->nullable()->after('workday_voucher_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['workday_voucher_amount', 'workday_min_hours']);
        });
    }
};
