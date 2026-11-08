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
        // A desk refund (DeskRefundService) is a payment_corrections row with
        // no subscription: the visit's amount_paid goes to 0 and net_amount
        // is the negative refund.
        Schema::table('payment_corrections', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_id')->nullable()->change();
        });

        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('desk_refunds_enabled')->default(false)->after('door_preferred_name_change_enabled');
            $table->unsignedSmallInteger('desk_refund_window_minutes')->default(30)->after('first_visit_after_close_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['desk_refunds_enabled', 'desk_refund_window_minutes']);
        });

        // Refund rows have no subscription, so they go first.
        DB::table('payment_corrections')->whereNull('subscription_id')->delete();

        Schema::table('payment_corrections', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_id')->nullable(false)->change();
        });
    }
};
