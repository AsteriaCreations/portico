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
        // A calendar-day deadline for staff to finalize Comp List additions
        // by — distinct from ends_at (which gates comp-reward voucher
        // eligibility, see vouchers:grant-comp-rewards). Purely informational/
        // reporting, same as on_probation: nothing reads it to block or gate
        // anything. Nullable since it's optional on every event, not just
        // pre-existing ones.
        Schema::table('events', function (Blueprint $table) {
            $table->date('comp_list_due_at')->nullable()->after('ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('comp_list_due_at');
        });
    }
};
