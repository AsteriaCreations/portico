<?php

use App\Models\CompReason;
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
            // A manager waiving this specific visit's entry fee (e.g. a
            // volunteer "House Sub" that night) — distinct from `comp`, which
            // is the member's own category being permanently free on
            // everything. Entry only; pool stays priced independently. See
            // docs/BLUEPRINT.md "Still open" ("House sub
            // comped rates").
            $table->enum('entry_covered_by', ['none', 'comp', 'regular_subscription', 'legacy_import', 'event_comp'])->default('none')->change();
            $table->foreignIdFor(CompReason::class)->nullable()->after('entry_covered_by')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(CompReason::class);
            $table->enum('entry_covered_by', ['none', 'comp', 'regular_subscription', 'legacy_import'])->default('none')->change();
        });
    }
};
