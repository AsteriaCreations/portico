<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The safe columns of each table the limited reporting account can't see
     * whole (docs/DEPLOYMENT.md §4a). A view of only those columns lets an
     * analyst run SELECT * on it; a column grant on the table itself refuses
     * SELECT * outright. A view runs with its creator's rights, so the
     * account only needs SELECT on the view. Never add a personal or
     * free-text column here: names, emails, birth dates, notes, reasons.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const VIEWS = [
        'analyst_members' => ['members', [
            'id', 'member_number', 'category_id', 'is_active', 'subscription_eligible', 'on_watchlist',
            'is_banned', 'banned_until', 'missing_paperwork', 'is_deceased', 'sponsor_id', 'email_opt_in',
            'probation_override_start', 'date_vetted', 'created_at', 'updated_at',
        ]],
        'analyst_attendance' => ['attendance', [
            'id', 'member_id', 'event_id', 'checked_in_by', 'checked_in_at', 'departed_at', 'entry_fee',
            'entry_coverage', 'entry_covered_by', 'voucher_coverage', 'amount_paid', 'payment_method',
            'comp_reason_id', 'register_shift_id', 'prepaid_ahead', 'created_at', 'updated_at',
        ]],
        'analyst_member_status_changes' => ['member_status_changes', [
            'id', 'member_id', 'status', 'value', 'changed_by', 'created_at',
        ]],
        'analyst_comp_requests' => ['comp_requests', [
            'id', 'event_id', 'member_id', 'comp_reason_id', 'requested_by', 'status', 'reviewed_by',
            'reviewed_at', 'attendance_id', 'created_at', 'updated_at',
        ]],
        'analyst_users' => ['users', [
            'id', 'role', 'active', 'member_id', 'created_at',
        ]],
        'analyst_membership_settings' => ['membership_settings', [
            'id', 'subscription_eligibility_threshold', 'subscription_eligibility_window_months',
            'probation_period_days', 'venue_capacity', 'currency', 'week_starts_on',
        ]],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::VIEWS as $view => [$table, $columns]) {
            // Dropped first: migrate:fresh drops tables, not views.
            DB::statement("DROP VIEW IF EXISTS {$view}");
            DB::statement("CREATE VIEW {$view} AS SELECT ".implode(', ', $columns)." FROM {$table}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_keys(self::VIEWS) as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }
    }
};
