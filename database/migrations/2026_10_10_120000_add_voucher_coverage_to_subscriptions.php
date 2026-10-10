<?php

use App\Models\Subscription;
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
        Schema::table('subscriptions', function (Blueprint $table) {
            // Same split as attendance: amount_paid is the money that changed
            // hands, voucher_coverage the account credit spent on the rest of
            // the price. See docs/BLUEPRINT.md "Vouchers".
            $table->decimal('voucher_coverage', 8, 2)->default(0)->after('amount_paid');
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignIdFor(Subscription::class)->nullable()->after('attendance_id')->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Subscription::class);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('voucher_coverage');
        });
    }
};
