<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

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
#[Fillable(['subscription_eligibility_threshold', 'subscription_eligibility_window_months', 'probation_period_days', 'guests_allowed_during_probation', 'max_guests_per_night', 'guest_followup_moves_category', 'guest_followup_category_id', 'venue_capacity', 'default_opening_float', 'event_window_buffer_minutes', 'checkin_close_minutes_overnight', 'checkin_close_minutes_same_day', 'first_visit_after_close_enabled', 'week_starts_on', 'age_of_majority', 'alcohol_flag_age', 'watchlist_notify_label', 'watchlist_probation_mode', 'watchlist_probation_days', 'watchlist_probation_blocks_guests', 'house_policy_banned_headline', 'house_policy_banned_instructions', 'house_policy_watchlist_headline', 'house_policy_watchlist_instructions', 'house_policy_under_majority_headline', 'house_policy_under_majority_instructions', 'house_policy_under_alcohol_age_headline', 'house_policy_under_alcohol_age_instructions', 'currency', 'locale', 'org_name', 'org_logo_path', 'role_labels', 'member_search_fields', 'checkin_display_name_field', 'member_email_required', 'hide_member_pii_by_default', 'active_patrons_show_staff_roles', 'vouchers_enabled', 'add_ons_enabled', 'showrunner_comp_requests_enabled', 'manager_perk_enabled', 'suspensions_enabled', 'pool_enabled', 'day_pass_future_events_enabled', 'prepay_enabled', 'register_shifts_enabled', 'cash_envelope_reminder_enabled', 'cash_exchanges_enabled', 'hold_desk_prepay_cash', 'separate_subscription_envelope', 'showrunner_payouts_enabled', 'instructor_payouts_enabled', 'visit_notes_enabled', 'behavior_notes_enabled', 'guests_enabled', 'door_username_rename_enabled', 'door_preferred_name_change_enabled', 'reset_desk_event_on_member_change', 'kiosk_checkin_enabled', 'staff_login_checkin_enabled', 'issue_reports_enabled', 'kiosk_show_code_enabled', 'kiosk_show_code_seconds', 'kiosk_subscribers_only', 'kiosk_result_seconds', 'kiosk_problem_result_seconds', 'kiosk_same_code_cooldown_seconds', 'kiosk_requests_per_minute', 'kiosk_code_email_batch_size', 'upstream_check_enabled', 'upstream_remote', 'upstream_branch', 'deploy_trigger_enabled', 'deploy_task_name'])]
class MembershipSetting extends Model
{
    /**
     * Never fillable either: only regenerateKioskDeviceSecret() writes it.
     *
     * @var list<string>
     */
    protected $hidden = ['kiosk_device_secret_hash'];

    protected function casts(): array
    {
        return [
            'subscription_eligibility_threshold' => 'integer',
            'subscription_eligibility_window_months' => 'integer',
            'probation_period_days' => 'integer',
            'guests_allowed_during_probation' => 'boolean',
            'max_guests_per_night' => 'integer',
            'guest_followup_moves_category' => 'boolean',
            'guest_followup_category_id' => 'integer',
            'venue_capacity' => 'integer',
            'default_opening_float' => 'decimal:2',
            'event_window_buffer_minutes' => 'integer',
            'checkin_close_minutes_overnight' => 'integer',
            'checkin_close_minutes_same_day' => 'integer',
            'first_visit_after_close_enabled' => 'boolean',
            'week_starts_on' => 'integer',
            'age_of_majority' => 'integer',
            'alcohol_flag_age' => 'integer',
            'watchlist_probation_days' => 'integer',
            'watchlist_probation_blocks_guests' => 'boolean',
            'role_labels' => 'array',
            'member_search_fields' => 'array',
            'member_email_required' => 'boolean',
            'hide_member_pii_by_default' => 'boolean',
            'active_patrons_show_staff_roles' => 'boolean',
            'vouchers_enabled' => 'boolean',
            'add_ons_enabled' => 'boolean',
            'showrunner_comp_requests_enabled' => 'boolean',
            'manager_perk_enabled' => 'boolean',
            'suspensions_enabled' => 'boolean',
            'pool_enabled' => 'boolean',
            'day_pass_future_events_enabled' => 'boolean',
            'prepay_enabled' => 'boolean',
            'register_shifts_enabled' => 'boolean',
            'cash_envelope_reminder_enabled' => 'boolean',
            'cash_exchanges_enabled' => 'boolean',
            'hold_desk_prepay_cash' => 'boolean',
            'separate_subscription_envelope' => 'boolean',
            'showrunner_payouts_enabled' => 'boolean',
            'instructor_payouts_enabled' => 'boolean',
            'visit_notes_enabled' => 'boolean',
            'behavior_notes_enabled' => 'boolean',
            'guests_enabled' => 'boolean',
            'door_username_rename_enabled' => 'boolean',
            'door_preferred_name_change_enabled' => 'boolean',
            'reset_desk_event_on_member_change' => 'boolean',
            'kiosk_checkin_enabled' => 'boolean',
            'staff_login_checkin_enabled' => 'boolean',
            'issue_reports_enabled' => 'boolean',
            'kiosk_show_code_enabled' => 'boolean',
            'kiosk_subscribers_only' => 'boolean',
            'kiosk_show_code_seconds' => 'integer',
            'kiosk_result_seconds' => 'integer',
            'kiosk_problem_result_seconds' => 'integer',
            'kiosk_same_code_cooldown_seconds' => 'integer',
            'kiosk_requests_per_minute' => 'integer',
            'kiosk_code_email_batch_size' => 'integer',
            'upstream_check_enabled' => 'boolean',
            'deploy_trigger_enabled' => 'boolean',
        ];
    }

    /** Container key holding this request's copy of the row. */
    private const CURRENT_INSTANCE = 'membership-settings.current';

    protected static function booted(): void
    {
        static::saved(fn () => static::forgetCurrent());
        static::deleted(fn () => static::forgetCurrent());
    }

    /**
     * The settings row, read once per request. The desk alone used to run
     * ~46 identical queries for it per interaction, and the checked-in
     * roster one per money cell on every 10s poll. Held in the service
     * container, not a PHP static: the container is rebuilt for every HTTP
     * request and every Pest test, so nothing leaks between them (a static
     * would survive RefreshDatabase). Saving or deleting the row drops the
     * copy; code that changes the table without the model (a raw DB::table
     * update) must call forgetCurrent() itself.
     */
    public static function current(): self
    {
        if (! app()->bound(self::CURRENT_INSTANCE)) {
            app()->instance(self::CURRENT_INSTANCE, static::loadCurrent());
        }

        return app(self::CURRENT_INSTANCE);
    }

    public static function forgetCurrent(): void
    {
        app()->forgetInstance(self::CURRENT_INSTANCE);
    }

    /**
     * The club's uploaded logo, or null to show org_name as text instead.
     */
    public function orgLogoUrl(): ?string
    {
        return filled($this->org_logo_path) ? Storage::disk('public')->url($this->org_logo_path) : null;
    }

    /**
     * Issues the shared secret a kiosk tablet sends with every scan and
     * returns it -- the only time it's available, since only its SHA-256 is
     * stored. Any tablet set up with the old one stops working.
     */
    public function regenerateKioskDeviceSecret(): string
    {
        $secret = Str::random(40);

        $this->forceFill(['kiosk_device_secret_hash' => hash('sha256', $secret)])->save();

        return $secret;
    }

    public function kioskDeviceSecretMatches(?string $secret): bool
    {
        return filled($secret)
            && $this->kiosk_device_secret_hash !== null
            && hash_equals($this->kiosk_device_secret_hash, hash('sha256', $secret));
    }

    private static function loadCurrent(): self
    {
        return static::query()->first() ?? static::create([
            'subscription_eligibility_threshold' => (int) config('membership.subscription_eligibility_threshold'),
            'probation_period_days' => (int) config('membership.probation_period_days'),
            'venue_capacity' => config('membership.venue_capacity'),
            'default_opening_float' => config('membership.default_opening_float'),
            'event_window_buffer_minutes' => (int) config('membership.event_window_buffer_minutes'),
            // No config/membership.php key -- these postdate that file's role
            // as the seed source. Preserves the app's original hardcoded
            // AdmissionPolicy behavior exactly (age of majority, and the
            // no-alcohol/check-ID flag age); a club outside that convention
            // adjusts them on the Membership Settings page.
            'age_of_majority' => 18,
            'alcohol_flag_age' => 21,
            // No config/membership.php key -- preserves the app's original
            // hardcoded USD assumption exactly (every money figure in the
            // app used to assume USD, either via a literal '$' or Filament's
            // own ->money() default). See self::formatMoney().
            'currency' => 'USD',
            // No config/membership.php key -- this setting postdates that
            // file's role as the seed source, so it's a plain hardcoded
            // default rather than a config() lookup.
            'hide_member_pii_by_default' => true,
            // Preserves the original always-shown behavior; see the migration.
            'active_patrons_show_staff_roles' => true,
            // Username-only by design -- that's the desk's real primary
            // lookup (the value on a member's card/tag). A club opts into
            // name / member number / etc. search on the Membership Settings
            // page. See Member::searchableColumns().
            'member_search_fields' => ['username'],
            // What the desk shows once a member is selected -- preserves the
            // app's original hardcoded behavior exactly, so upgrading never
            // silently changes what staff see. See Member::displayName().
            'checkin_display_name_field' => 'preferred_name',
            // What the desk always did. See AdmissionPolicy::hasIncompleteIdentity().
            'member_email_required' => true,
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
            // A new capability, so off until a club opts in. Only reachable
            // while register_shifts_enabled is on (it fires on closing a box).
            'cash_envelope_reminder_enabled' => false,
            // A new capability, so off until a club opts in. Only reachable
            // while register_shifts_enabled is on (it's a cash-box action).
            'cash_exchanges_enabled' => false,
            // What the desk always did: cash for a later event goes in that
            // event's prepay envelope, not the box. See CheckInService.
            'hold_desk_prepay_cash' => true,
            // What the envelope reminder always did. See
            // ManagesCashBox::envelopeReminderBody().
            'separate_subscription_envelope' => true,
            // What the desk always did. See Event::isCheckInClosedFor().
            'first_visit_after_close_enabled' => true,
            // Both brand-new features -- on by default so they work out of
            // the box, same reasoning as suspensions_enabled above.
            'showrunner_payouts_enabled' => true,
            'instructor_payouts_enabled' => true,
            // Default true so an upgrading install keeps behavior it already
            // had; a fresh install can turn these off on the Feature Flags page.
            'visit_notes_enabled' => true,
            'behavior_notes_enabled' => true,
            // What the desk always did: guests on, but not while the sponsor
            // is on probation. See Member::canSponsorGuests().
            'guests_enabled' => true,
            'guests_allowed_during_probation' => false,
            // What marking a follow-up sent always did: move the guest to
            // Irregular (a null category means Irregular). See
            // self::guestFollowupTargetCategory().
            'guest_followup_moves_category' => true,
            'guest_followup_category_id' => null,
            // Renaming was Manager+ only; a club opts Door in on the
            // Feature Flags page. See the rename-member-username gate.
            'door_username_rename_enabled' => false,
            // Same shape: Manager+ only (member edit page) until a club opts
            // Door in. See the change-member-preferred-name gate.
            'door_preferred_name_change_enabled' => false,
            // Fixes check-ins landing on the wrong one of two overlapping
            // events, so on unless a club opts out. See
            // CheckIn::updatedDataMemberId().
            'reset_desk_event_on_member_change' => true,
            // Day passes for tonight's events only; a club opts back in to
            // selling them ahead on the Feature Flags page.
            'day_pass_future_events_enabled' => false,
            // A new capability, so off until a club opts in. See the
            // manage-kiosk-token gate and Member::ensureKioskToken().
            'kiosk_checkin_enabled' => false,
            // A new capability, so off until a club opts in. See
            // StaffLoginCheckInService.
            'staff_login_checkin_enabled' => false,
            // A new capability, so off until a club opts in. See
            // IssueReportService.
            'issue_reports_enabled' => false,
            // What the kiosk always did. See KioskCheckInService::scan().
            'kiosk_subscribers_only' => true,
            // What "Show on kiosk" always used. See KioskCodeDisplay::seconds().
            'kiosk_show_code_seconds' => 60,
            // What the kiosk screen always used. See resources/js/kiosk.js.
            'kiosk_result_seconds' => 4,
            'kiosk_problem_result_seconds' => 7,
            'kiosk_same_code_cooldown_seconds' => 8,
            // The kiosk rate limit it always had. See AppServiceProvider.
            'kiosk_requests_per_minute' => 30,
            // The batch it always sent. See KioskCodeMailer::batchSize().
            'kiosk_code_email_batch_size' => 50,
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

    /**
     * Applies the installation's configured language to the running process --
     * the app locale (which Carbon follows) and the Number locale (which does
     * not, and is a static that would otherwise outlive a changed setting).
     * A null or unrecognized value leaves config('app.locale') in force.
     *
     * Called by SetLocale for web requests, and directly by anything that
     * renders text outside a request -- a console command sending email.
     */
    public static function applyConfiguredLocale(): void
    {
        $locale = static::current()->locale;

        if ($locale !== null && array_key_exists($locale, static::availableLocales())) {
            app()->setLocale($locale);
        }

        Number::useLocale(app()->getLocale());
    }

    /**
     * The languages this installation can switch to, keyed by locale code
     * with each language's own name as the label: English (the source
     * language, which needs no file) plus every lang/{code}.json present.
     *
     * @return array<string, string>
     */
    public static function availableLocales(): array
    {
        $codes = collect(glob(lang_path('*.json')) ?: [])
            ->map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME))
            ->prepend('en')
            ->unique()
            ->sort()
            ->values();

        return $codes
            ->mapWithKeys(fn (string $code): array => [
                $code => Str::ucfirst(\Locale::getDisplayName($code, $code)) ?: $code,
            ])
            ->all();
    }

    // Every hand-formatted money string in the app (Analytics widgets, the
    // check-in desk's live totals/notifications, model helper text) routes
    // through this, so the club-configured currency shows up everywhere a
    // dollar sign used to be hardcoded. Filament's own ->money() table
    // columns don't call this -- see the panel-wide Table::configureUsing()
    // default in AppServiceProvider instead, which covers those the same
    // way without touching every individual column.
    /**
     * The name staff see in "Notify <label>" for a watchlisted member. Set on
     * Membership Settings; blank falls back to WATCHLIST_NOTIFY_LABEL in .env
     * (config/membership.php), which is how installs set it before this existed.
     */
    public static function watchlistNotifyLabel(): string
    {
        return static::current()->watchlist_notify_label ?: (string) config('membership.watchlist_notify_label');
    }

    /**
     * The situations whose Check-In Desk wording a club can set under House
     * Policy on Membership Settings.
     */
    public const HOUSE_POLICY_SITUATIONS = ['banned', 'watchlist', 'under_majority', 'under_alcohol_age'];

    /**
     * The club's own wording for one situation on the desk's status bar, with
     * :age (that situation's configured age) and :label (watchlist notify
     * label) filled in. Null for a part left blank, meaning the shipped
     * wording. Text only: it never changes the admission decision.
     *
     * @return array{headline: string|null, instructions: string|null}
     */
    public static function housePolicy(string $situation): array
    {
        $settings = static::current();
        $replace = [
            ':age' => (string) ($situation === 'under_majority' ? $settings->age_of_majority : $settings->alcohol_flag_age),
            ':label' => static::watchlistNotifyLabel(),
        ];
        $text = fn (?string $value): ?string => filled($value) ? strtr(trim($value), $replace) : null;

        return [
            'headline' => $text($settings->{"house_policy_{$situation}_headline"}),
            'instructions' => $text($settings->{"house_policy_{$situation}_instructions"}),
        ];
    }

    /**
     * Where a guest goes once their follow-up is marked sent, or null to
     * leave them a Guest. An unset or deleted choice means Irregular, which
     * is what marking it sent always did.
     */
    public static function guestFollowupTargetCategory(): ?Category
    {
        $settings = static::current();

        if (! $settings->guest_followup_moves_category) {
            return null;
        }

        return $settings->guestFollowupCategory ?? Category::where('name', 'Irregular')->firstOrFail();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function guestFollowupCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'guest_followup_category_id');
    }

    /**
     * How many days watchlist probation lasts, or null when it's off.
     * watchlist_probation_mode 'same' follows new-member probation, so
     * changing that setting moves this too; 'custom' is the club's own
     * watchlist_probation_days. Every caller goes through here, never the
     * raw columns.
     */
    public static function watchlistProbationDays(): ?int
    {
        $settings = static::current();

        $days = match ($settings->watchlist_probation_mode) {
            'custom' => $settings->watchlist_probation_days,
            'off' => null,
            default => $settings->probation_period_days,
        };

        return $days ?: null;
    }

    /**
     * Whether watchlist probation stops the member sponsoring guests. 'same'
     * mirrors the new-member rule (guests_allowed_during_probation).
     */
    public static function watchlistProbationBlocksGuests(): bool
    {
        $settings = static::current();

        return match ($settings->watchlist_probation_mode) {
            'custom' => $settings->watchlist_probation_blocks_guests,
            'off' => false,
            default => ! $settings->guests_allowed_during_probation,
        };
    }

    /**
     * The start of the club's week containing $date (default now). Every
     * "this week" figure -- the weekly Analytics widgets and the Cleaning
     * Checklist reset -- goes through this and endOfWeek(). week_starts_on
     * is a Carbon day number (0 = Sunday … 6 = Saturday); null follows the
     * install's language, which is what these used before the setting existed.
     */
    public static function startOfWeek(?CarbonInterface $date = null): CarbonInterface
    {
        return ($date ?? now())->copy()->startOfWeek(static::current()->week_starts_on);
    }

    /**
     * The end of the club's week containing $date (default now); see startOfWeek().
     */
    public static function endOfWeek(?CarbonInterface $date = null): CarbonInterface
    {
        $startsOn = static::current()->week_starts_on;

        return ($date ?? now())->copy()->endOfWeek($startsOn === null ? null : ($startsOn + 6) % 7);
    }

    public static function formatMoney(float $amount): string
    {
        return Number::currency($amount, in: static::current()->currency) ?: number_format($amount, 2);
    }
}
