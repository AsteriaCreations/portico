<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('add_ons', function (Blueprint $table) {
            $table->boolean('is_overnight')->default(false)->after('max_per_night');
        });

        // Backfill for the two add-ons that already mean this in practice --
        // leaving them false would misrepresent real catalog data, not just
        // suppress a new action. See docs/BLUEPRINT.md
        // "Still open" (overnight guest handling).
        DB::table('add_ons')
            ->whereIn('name', ['Private room rental', 'Sleepover'])
            ->update(['is_overnight' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('add_ons', function (Blueprint $table) {
            $table->dropColumn('is_overnight');
        });
    }
};
