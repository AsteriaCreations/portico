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
            // An independent settlement line: unlike entry/add-on coverage it
            // isn't tied to a single component, it just discounts whatever's
            // left of the combined total after entry and add-on coverage are
            // applied. See docs/BLUEPRINT.md "Vouchers".
            $table->decimal('voucher_coverage', 8, 2)->default(0)->after('entry_covered_by');
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
