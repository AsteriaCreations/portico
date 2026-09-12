<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A single row, not a history — every club-wide tunable that used to live
 * only in config/membership.php (env-only, needing a code change + redeploy
 * to adjust) now lives here instead, admin-editable via two Filament pages
 * over the same row: App\Filament\Admin\Pages\MembershipSettings for the
 * operational tunables, App\Filament\Admin\Pages\FeatureFlags for the
 * boolean feature toggles. The migration that creates this table also seeds
 * the one row from config/membership.php's values, so config() still
 * supplies the initial defaults for a fresh install — it's just no longer
 * read anywhere else in the app afterward.
 */
#[Fillable(['subscription_eligibility_threshold', 'probation_period_days', 'venue_capacity', 'default_opening_float', 'event_window_buffer_minutes', 'org_name', 'role_labels', 'member_search_fields', 'checkin_display_name_field', 'hide_member_pii_by_default', 'vouchers_enabled', 'add_ons_enabled', 'showrunner_comp_requests_enabled', 'manager_perk_enabled', 'suspensions_enabled', 'pool_enabled', 'prepay_enabled', 'register_shifts_enabled', 'showrunner_payouts_enabled', 'instructor_payouts_enabled', 'showrunner_door_includes_pool', 'showrunner_door_includes_addons', 'visit_notes_enabled', 'behavior_notes_enabled', 'upstream_check_enabled', 'upstream_remote', 'upstream_branch', 'deploy_trigger_enabled', 'deploy_task_name'])]
class MembershipSetting extends Model
{
    protected function casts(): array
    {
        return [
            'subscription_eligibility_threshold' => 'integer',
            'probation_period_days' => 'integer',
            'venue_capacity' => 'integer',
            'default_opening_float' => 'float',
            'event_window_buffer_minutes' => 'integer',
            'role_labels' => 'array',
            'member_search_fields' => 'array',
            'hide_member_pii_by_default' => 'boolean',
            'vouchers_enabled' => 'boolean',
            'add_ons_enabled' => 'boolean',
            'showrunner_comp_requests_enabled' => 'boolean',
            'manager_perk_enabled' => 'boolean',
            'suspensions_enabled' => 'boolean',
            'pool_enabled' => 'boolean',
            'prepay_enabled' => 'boolean',
            'register_shifts_enabled' => 'boolean',
            'showrunner_payouts_enabled' => 'boolean',
            'instructor_payouts_enabled' => 'boolean',
            'showrunner_door_includes_pool' => 'boolean',
            'showrunner_door_includes_addons' => 'boolean',
            'visit_notes_enabled' => 'boolean',
            'behavior_notes_enabled' => 'boolean',
            'upstream_check_enabled' => 'boolean',
            'deploy_trigger_enabled' => 'boolean',
        ];
    }

    // No caching layer here on purpose: a static in-memory cache would leak
    // a stale instance across Pest tests within the same process (Refresh
    // Database resets the database, not PHP statics) -- a plain query every
    // call is cheap enough (one row, indexed) that it isn't worth the risk.
    public static function current(): self
    {
        return static::query()->first() ?? static::create([
            'subscription_eligibility_threshold' => (int) config('membership.subscription_eligibility_threshold'),
            'probation_period_days' => (int) config('membership.probation_period_days'),
            'venue_capacity' => config('membership.venue_capacity'),
            'default_opening_float' => config('membership.default_opening_float'),
            'event_window_buffer_minutes' => (int) config('membership.event_window_buffer_minutes'),
            // No config/membership.php key -- this setting postdates that
            // file's role as the seed source, so it's a plain hardcoded
            // default rather than a config() lookup.
            'hide_member_pii_by_default' => true,
            // Username-only by design -- that's the desk's real primary
            // lookup (the value on a member's card/tag). A club opts into
            // name / member number / etc. search on the Membership Settings
            // page. See Member::searchableColumns().
            'member_search_fields' => ['username'],
            // What the desk shows once a member is selected -- preserves the
            // app's original hardcoded behavior exactly, so upgrading never
            // silently changes what staff see. See Member::displayName().
            'checkin_display_name_field' => 'preferred_name',
            // Default true so an install upgrading to this version keeps a
            // feature it may already rely on; a fresh install turns off what
            // it doesn't need on the Feature Flags page.
            'vouchers_enabled' => true,
            'add_ons_enabled' => true,
            'showrunner_comp_requests_enabled' => true,
            'manager_perk_enabled' => true,
            // A brand-new feature, not a preserve-existing-behavior default
            // -- on by default so it works out of the box; a club that
            // doesn't want suspensions can turn it off.
            'suspensions_enabled' => true,
            // Default true so an upgrading install keeps behavior it already
            // had; a fresh install can turn these off on the Feature Flags page.
            'pool_enabled' => true,
            'prepay_enabled' => true,
            'register_shifts_enabled' => true,
            // Both brand-new features -- on by default so they work out of
            // the box, same reasoning as suspensions_enabled above.
            'showrunner_payouts_enabled' => true,
            'instructor_payouts_enabled' => true,
            // The commission base defaults to entry revenue only; a club
            // opts in to counting pool/add-on revenue toward "the door".
            'showrunner_door_includes_pool' => false,
            'showrunner_door_includes_addons' => false,
            // Default true so an upgrading install keeps behavior it already
            // had; a fresh install can turn these off on the Feature Flags page.
            'visit_notes_enabled' => true,
            'behavior_notes_enabled' => true,
            // Off and unconfigured -- a fresh install has no upstream remote
            // at all, and this never silently starts running git commands.
            // See App\Services\UpstreamUpdateChecker.
            'upstream_check_enabled' => false,
            'upstream_remote' => null,
            'upstream_branch' => 'main',
            // Off and unconfigured for the same reason as upstream_check_enabled
            // above -- see App\Services\DeployTrigger.
            'deploy_trigger_enabled' => false,
            'deploy_task_name' => null,
        ]);
    }
}
