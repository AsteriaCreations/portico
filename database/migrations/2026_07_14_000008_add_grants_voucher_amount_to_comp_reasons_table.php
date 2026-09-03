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
        // Null = no automatic voucher for this reason. If set, a member
        // comped for this reason gets a voucher of this amount once the
        // event ends (see vouchers:grant-comp-rewards). Generalizes the
        // "presenter" case to any comp reason, matching comp_reasons'
        // existing "editable settings data, not a hardcoded enum" shape.
        Schema::table('comp_reasons', function (Blueprint $table) {
            $table->decimal('grants_voucher_amount', 8, 2)->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comp_reasons', function (Blueprint $table) {
            $table->dropColumn('grants_voucher_amount');
        });
    }
};
