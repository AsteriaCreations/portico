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
            // The historical Excel import knows a member paid less than the
            // event's list fee, but not why (the sheet never recorded which
            // subscription/comp covered the difference) — see
            // docs/BLUEPRINT.md "Historical attendance import".
            $table->enum('entry_covered_by', ['none', 'comp', 'regular_subscription', 'legacy_import'])->default('none')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->enum('entry_covered_by', ['none', 'comp', 'regular_subscription'])->default('none')->change();
        });
    }
};
