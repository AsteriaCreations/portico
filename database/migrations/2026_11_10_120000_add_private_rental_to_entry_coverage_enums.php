<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BEFORE = ['none', 'comp', 'regular_subscription', 'legacy_import', 'event_comp', 'host'];

    /**
     * Recreated unchanged after widening entry_covered_by. Widening rebuilds
     * the table on SQLite, which refuses while this view
     * (create_analyst_views) reads it, so it's dropped around the change.
     */
    private const ATTENDANCE_VIEW = 'CREATE VIEW analyst_attendance AS SELECT id, member_id, event_id, checked_in_by, checked_in_at, departed_at, entry_fee, entry_coverage, entry_covered_by, voucher_coverage, amount_paid, payment_method, comp_reason_id, register_shift_id, prepaid_ahead, created_at, updated_at FROM attendance';

    /**
     * A spot the renter paid for at a private rental (included or extra
     * people) covers that attendee's entry: EntryCoverageSource::PrivateRental.
     * Instructor pay rates are keyed on the same values.
     */
    public function up(): void
    {
        $this->setSources([...self::BEFORE, 'private_rental']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->setSources(self::BEFORE);
    }

    /**
     * @param  list<string>  $sources
     */
    private function setSources(array $sources): void
    {
        DB::statement('DROP VIEW IF EXISTS analyst_attendance');

        Schema::table('attendance', function (Blueprint $table) use ($sources) {
            $table->enum('entry_covered_by', $sources)->default('none')->change();
        });

        DB::statement(self::ATTENDANCE_VIEW);

        Schema::table('instructor_pay_rates', function (Blueprint $table) use ($sources) {
            $table->enum('entry_covered_by', $sources)->change();
        });
    }
};
