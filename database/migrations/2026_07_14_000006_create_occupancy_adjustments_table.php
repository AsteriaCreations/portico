<?php

use App\Models\User;
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
        // Append-only ledger of known departures (or corrections) adjusting
        // the building's effective occupancy for a given night — attendance
        // rows are never rewritten (CONTRIBUTING.md rule 5), so a departure can't
        // be "un-counted" any other way. See
        // docs/BLUEPRINT.md "Prepay events".
        Schema::create('occupancy_adjustments', function (Blueprint $table) {
            $table->id();
            $table->date('for_date');
            $table->integer('delta'); // negative = departures freeing up room
            $table->string('reason', 255)->nullable();
            $table->foreignIdFor(User::class, 'recorded_by')->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('occupancy_adjustments');
    }
};
