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
        // Snapshotted at time of purchase, same as this table's existing
        // name/price columns -- a later catalog edit to add_ons.is_overnight
        // must never rewrite what a past attendance row actually was.
        Schema::table('attendance_add_ons', function (Blueprint $table) {
            $table->boolean('is_overnight')->default(false)->after('price');
        });

        // Backfill matched by the snapshotted name column (not add_on_id,
        // which is nullable) so an already-checked-in, non-departed guest
        // becomes visible/accountable under the new logic without a fresh
        // check-in. See the sibling add_ons migration.
        DB::table('attendance_add_ons')
            ->whereIn('name', ['Private room rental', 'Sleepover'])
            ->update(['is_overnight' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_add_ons', function (Blueprint $table) {
            $table->dropColumn('is_overnight');
        });
    }
};
