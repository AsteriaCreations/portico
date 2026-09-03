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
        // Lets the check-in page surface this event ahead of its own date,
        // so the desk can take a walk-in prepayment for it. See
        // docs/BLUEPRINT.md "Prepay events".
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('door_prepay_enabled')->default(false)->after('pool_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('door_prepay_enabled');
        });
    }
};
