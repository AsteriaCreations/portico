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
            // A one-time purchase covering pool for exactly this event,
            // distinct from pool_subscription (a whole calendar month). See
            // docs/BLUEPRINT.md "Still open" (pool pass).
            $table->enum('pool_covered_by', ['none', 'comp', 'pool_subscription', 'legacy_import', 'day_pass'])->default('none')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->enum('pool_covered_by', ['none', 'comp', 'pool_subscription', 'legacy_import'])->default('none')->change();
        });
    }
};
