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
            $table->enum('entry_covered_by', ['none', 'comp', 'regular_subscription', 'legacy_import', 'event_comp', 'host'])->default('none')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->enum('entry_covered_by', ['none', 'comp', 'regular_subscription', 'legacy_import', 'event_comp'])->default('none')->change();
        });
    }
};
