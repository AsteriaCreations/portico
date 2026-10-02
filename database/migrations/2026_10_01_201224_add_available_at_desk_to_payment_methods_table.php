<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Whether a method is offered by the Check-In Desk's payment pickers
        // (check-in, subscription sale, day pass). Picking "Other" at check-in
        // still records the full entry as paid, which invited double-counting
        // money taken some other way -- so it defaults off at the desk.
        // The desk reads PaymentMethod::deskOptions(); "Record other payment"
        // and the Manager+ edit forms still list every active method.
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('available_at_desk')->default(true)->after('one_time_only');
        });

        DB::table('payment_methods')->where('code', 'other')->update(['available_at_desk' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('available_at_desk');
        });
    }
};
