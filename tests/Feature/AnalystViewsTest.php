<?php

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * What the limited reporting account sees (docs/DEPLOYMENT.md §4a). Pinned
 * here so a view can't quietly gain a personal column, or break when a later
 * migration drops or renames a column it reads.
 *
 * @return array<string, list<string>>
 */
function analystViewColumns(): array
{
    return [
        'analyst_members' => [
            'id', 'member_number', 'category_id', 'is_active', 'subscription_eligible', 'on_watchlist',
            'is_banned', 'banned_until', 'missing_paperwork', 'is_deceased', 'sponsor_id', 'email_opt_in',
            'probation_override_start', 'date_vetted', 'created_at', 'updated_at',
        ],
        'analyst_attendance' => [
            'id', 'member_id', 'event_id', 'checked_in_by', 'checked_in_at', 'departed_at', 'entry_fee',
            'entry_coverage', 'entry_covered_by', 'voucher_coverage', 'amount_paid', 'payment_method',
            'comp_reason_id', 'register_shift_id', 'prepaid_ahead', 'created_at', 'updated_at',
        ],
        'analyst_member_status_changes' => ['id', 'member_id', 'status', 'value', 'changed_by', 'created_at'],
        'analyst_comp_requests' => [
            'id', 'event_id', 'member_id', 'comp_reason_id', 'requested_by', 'status', 'reviewed_by',
            'reviewed_at', 'attendance_id', 'created_at', 'updated_at',
        ],
        'analyst_users' => ['id', 'role', 'active', 'member_id', 'created_at'],
        'analyst_membership_settings' => [
            'id', 'subscription_eligibility_threshold', 'subscription_eligibility_window_months',
            'probation_period_days', 'venue_capacity', 'currency', 'week_starts_on',
        ],
    ];
}

test('each analyst view exposes exactly its safe columns and can be read', function () {
    foreach (analystViewColumns() as $view => $columns) {
        expect(Schema::getColumnListing($view))->toEqualCanonicalizing($columns, $view);

        DB::table($view)->get();
    }
});

test('the analyst views are views, not tables, so a data reset never has to classify them', function () {
    $views = collect(Schema::getViews())->pluck('name')->all();

    expect($views)->toEqualCanonicalizing(array_keys(analystViewColumns()))
        ->and(array_intersect(Schema::getTableListing(schemaQualified: false), $views))->toBe([]);
});

test('a member read through analyst_members has no name, email or birth date', function () {
    $member = Member::factory()->create(['first_name' => 'Pat', 'last_name' => 'Doe', 'email' => 'pat@example.com', 'dob' => '1990-01-01']);

    $row = (array) DB::table('analyst_members')->where('id', $member->id)->first();

    expect($row['member_number'])->toBe($member->member_number)
        ->and($row)->not->toHaveKeys(['first_name', 'last_name', 'email', 'dob', 'username', 'notes']);
});
