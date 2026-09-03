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
        // Extensible list of reasons a specific visit was comped (e.g. "House
        // Sub") — editable settings data, not a hardcoded enum, so a manager
        // can add a new one as it comes up. See docs/BLUEPRINT.md
        // "Still open" for the "House sub comped rates" note this scopes.
        Schema::create('comp_reasons', function (Blueprint $table) {
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
        Schema::dropIfExists('comp_reasons');
    }
};
