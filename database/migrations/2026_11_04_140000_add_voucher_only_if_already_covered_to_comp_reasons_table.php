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
        // When true, this reason's voucher goes only to visits whose entry
        // was already covered (subscription, comped category, host), i.e.
        // the comp itself waived nothing. A donation night then gives a
        // payer free entry and an already-free member a voucher, from the
        // one reason. Off keeps the old "every comped visit" behavior.
        Schema::table('comp_reasons', function (Blueprint $table) {
            $table->boolean('voucher_only_if_already_covered')->default(false)->after('grants_voucher_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comp_reasons', function (Blueprint $table) {
            $table->dropColumn('voucher_only_if_already_covered');
        });
    }
};
