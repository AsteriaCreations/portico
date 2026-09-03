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
        // Editable catalog of priced extras a member can be charged for at
        // check-in on top of the normal entry/pool fee (e.g. "Private room
        // rental", "Sleepover") — editable settings data, not a hardcoded
        // list, same shape as comp_reasons/payment_methods. See
        // docs/BLUEPRINT.md "Still open".
        Schema::create('add_ons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->decimal('price', 8, 2);
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
        Schema::dropIfExists('add_ons');
    }
};
