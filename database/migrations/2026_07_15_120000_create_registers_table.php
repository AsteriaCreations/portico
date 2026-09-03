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
        // A physical cashbox at the desk. Every register handles cash — there's
        // no "handles_cash" flag — a non-cash check-in station simply never
        // opens a shift against any register at all, which is what keeps
        // "Cash" from ever appearing as a payment method there. Editable
        // settings data, not a hardcoded enum, same pattern as event_types /
        // comp_reasons, so the club can add a second cashbox later.
        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('description', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registers');
    }
};
