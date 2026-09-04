<?php

use App\Models\Attendance;
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
        // Append-only record of which add-ons were charged on a given
        // attendance row, name/price snapshotted at time of purchase (same
        // "never recompute" principle as attendance's own entry_fee) so a
        // later catalog rename/price change never rewrites history. `price`
        // is what was actually paid for this line (0 if fully covered) --
        // the same column meaning for every row, subscribable or not -- so
        // it's still folded straight into attendance.amount_paid and
        // RegisterShiftService needs no changes. `fee`/`coverage`/
        // `covered_by` are only ever set for a subscribable add-on's line
        // (Pool, at launch) -- null/0/null for an ordinary flat add-on,
        // exactly the shape this table already had before subscribable
        // add-ons existed. See docs/BLUEPRINT.md "Fee pipeline".
        Schema::create('attendance_add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Attendance::class)->constrained()->cascadeOnDelete();
            $table->foreignId('add_on_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 60);
            $table->decimal('price', 8, 2);
            $table->decimal('fee', 8, 2)->nullable();
            $table->decimal('coverage', 8, 2)->default(0);
            $table->enum('covered_by', ['none', 'comp', 'subscription', 'day_pass', 'legacy_import'])->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_add_ons');
    }
};
