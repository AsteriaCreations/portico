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
        // "never recompute" principle as attendance's own entry_fee/
        // pool_fee) so a later catalog rename/price change never rewrites
        // history. The money itself is folded into attendance.amount_paid,
        // not tracked here — this table is the "what was included" audit
        // trail, not a separate payment ledger. See
        // docs/BLUEPRINT.md "Still open".
        Schema::create('attendance_add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Attendance::class)->constrained()->cascadeOnDelete();
            $table->foreignId('add_on_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 60);
            $table->decimal('price', 8, 2);
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
