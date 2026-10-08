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
        // Whether the visit was paid ahead of its event's night -- a desk
        // prepay for a not-yet-active event, or a Prepay List entry. A
        // snapshot fact like register_shift_id: arrival later sets
        // checked_in_at, so "was this a prepay" can't be read back from it.
        // Its cash is held outside the register until the event (see
        // RegisterShiftService::heldPrepayCashCents()). No backfill: a
        // prepay recorded before this column existed stayed in the box it
        // was taken on, and flagging it now would move closed shifts'
        // expected cash.
        Schema::table('attendance', function (Blueprint $table) {
            $table->boolean('prepaid_ahead')->default(false)->after('register_shift_id');
        });

        // Snapshotted with the removal, so a prepay removed after its shift
        // closed is added back to that shift's held figure too.
        Schema::table('visit_removals', function (Blueprint $table) {
            $table->boolean('prepaid_ahead')->default(false)->after('after_shift_closed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visit_removals', function (Blueprint $table) {
            $table->dropColumn('prepaid_ahead');
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn('prepaid_ahead');
        });
    }
};
