<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rental payments for the limited reporting account without the refund
     * reason (staff free text) -- same reasoning as create_analyst_views.
     */
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS analyst_private_rental_payments');
        DB::statement('CREATE VIEW analyst_private_rental_payments AS SELECT id, private_rental_id, amount, transaction_fee, payment_method, register_shift_id, recorded_by, created_at FROM private_rental_payments');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS analyst_private_rental_payments');
    }
};
