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
        Schema::create('instructor_pay_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_type_id')->constrained()->cascadeOnDelete();
            $table->enum('entry_covered_by', ['none', 'comp', 'regular_subscription', 'legacy_import', 'event_comp', 'host']);
            $table->decimal('rate', 8, 2);

            $table->unique(['event_type_id', 'entry_covered_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instructor_pay_rates');
    }
};
