<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Wipes every member, event and the transactional history hanging off them
 * (attendance, subscriptions, vouchers, register shifts, every append-only
 * ledger…) while keeping staff user accounts and all club configuration —
 * e.g. to clear demo/training data before go-live, or to start a fresh
 * season on the same install. Callers (`data:reset`, the Owner-only
 * Technical page action) take a backup first; this class only deletes.
 *
 * Deliberately bypasses Eloquent: the append-only ledgers are policy-locked
 * against deletion, and observers would try to write audit rows for records
 * that are about to disappear. Row-by-row DELETEs (not TRUNCATE) so the
 * whole wipe is one transaction on MariaDB, where TRUNCATE commits implicitly.
 */
class OperationalDataReset
{
    /**
     * Deleted in this order — children before the parents their FKs point at.
     *
     * @var list<string>
     */
    public const CLEARED_TABLES = [
        'attendance_add_ons',
        'attendance_behavior_notes',
        'payment_corrections',
        'visit_removals',
        'comp_requests',
        'vouchers',
        'add_on_day_passes',
        'ban_exceptions',
        'watchlist_reviews',
        'member_status_changes',
        'member_username_changes',
        'member_paperwork',
        'member_skill',
        'attendance',
        'subscriptions',
        'miscellaneous_payments',
        'instructor_payouts',
        'register_drops',
        'register_shifts',
        'add_on_event',
        'events',
        'members',
        'occupancy_adjustments',
        'cleaning_task_completions',
        'notifications',
    ];

    /**
     * Left untouched: sign-in accounts and the framework's own tables, plus
     * every configuration table a club sets up once. A new table must be
     * added to one of these two lists (OperationalDataResetTest enforces it).
     *
     * @var list<string>
     */
    public const KEPT_TABLES = [
        'users',
        'password_reset_tokens',
        'sessions',
        'user_capabilities',
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'command_runs',
        'membership_settings',
        'categories',
        'event_types',
        'plans',
        'comp_reasons',
        'registers',
        'payment_methods',
        'add_ons',
        'paperwork_types',
        'skills',
        'cleaning_tasks',
        'showrunner_payout_tiers',
        'instructor_pay_rates',
    ];

    /**
     * @return array<string, int> rows deleted, keyed by table
     */
    public function reset(): array
    {
        return DB::transaction(function (): array {
            // FKs into members that would otherwise block the delete: a staff
            // account's own member link, and the members→members sponsor
            // self-reference (InnoDB checks it row by row mid-DELETE).
            DB::table('users')->whereNotNull('member_id')->update(['member_id' => null]);
            DB::table('members')->whereNotNull('sponsor_id')->update(['sponsor_id' => null]);

            $deleted = [];

            foreach (self::CLEARED_TABLES as $table) {
                $deleted[$table] = DB::table($table)->delete();
            }

            return $deleted;
        });
    }
}
