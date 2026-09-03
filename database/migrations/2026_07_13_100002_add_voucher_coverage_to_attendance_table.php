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
        Schema::table('attendance', function (Blueprint $table) {
            // A third, independent settlement line: unlike entry/pool coverage
            // it isn't tied to a component, it just discounts whatever's left
            // of the combined total after entry/pool coverage is applied. See
            // docs/BLUEPRINT.md "Vouchers".
            $table->decimal('voucher_coverage', 8, 2)->default(0)->after('pool_covered_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn('voucher_coverage');
        });
    }
};
