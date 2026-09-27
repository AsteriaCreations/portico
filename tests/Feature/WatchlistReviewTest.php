<?php

use App\Enums\MemberStatusField;
use App\Enums\Role;
use App\Enums\WatchlistReviewDecision;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Filament\Admin\Resources\Members\Pages\EditMember;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\MemberStatusChange;
use App\Models\User;
use App\Models\WatchlistReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function watchlisted(array $attributes = []): Member
{
    return Member::factory()->create([
        'on_watchlist' => true,
        'watchlist_reason' => 'Prior incident',
        ...$attributes,
    ]);
}

// ---- Review due -----------------------------------------------------------

test('a watchlist review is due once its date is today or past', function () {
    expect(watchlisted(['watchlist_review_on' => today()])->isWatchlistReviewDue())->toBeTrue()
        ->and(watchlisted(['watchlist_review_on' => today()->subWeek()])->isWatchlistReviewDue())->toBeTrue();
});

test('a watchlist review is not due for a future date, no date, or a member not on the watchlist', function () {
    expect(watchlisted(['watchlist_review_on' => today()->addDay()])->isWatchlistReviewDue())->toBeFalse()
        ->and(watchlisted(['watchlist_review_on' => null])->isWatchlistReviewDue())->toBeFalse()
        ->and(Member::factory()->create(['on_watchlist' => false, 'watchlist_review_on' => today()->subDay()])->isWatchlistReviewDue())->toBeFalse();
});

test('the Members nav badge counts only due watchlist reviews', function () {
    watchlisted(['watchlist_review_on' => today()]);
    watchlisted(['watchlist_review_on' => today()->subMonth()]);
    watchlisted(['watchlist_review_on' => today()->addMonth()]);
    watchlisted(['watchlist_review_on' => null]);

    expect(MemberResource::getNavigationBadge())->toBe('2');
});

test('the Members nav badge is hidden when nothing is due', function () {
    watchlisted(['watchlist_review_on' => today()->addMonth()]);

    expect(MemberResource::getNavigationBadge())->toBeNull();
});

// ---- Owner-only removal ---------------------------------------------------

test('a Manager or Admin cannot take a member off the watchlist', function (Role $role) {
    actingAs(User::factory()->create(['role' => $role]));
    $member = watchlisted();

    expect(fn () => $member->update(['on_watchlist' => false]))->toThrow(ValidationException::class);

    expect($member->fresh()->on_watchlist)->toBeTrue();
})->with([Role::Manager, Role::Admin]);

test('a Manager can still put a member on the watchlist and set its review date', function () {
    actingAs(User::factory()->create(['role' => Role::Manager]));
    $member = Member::factory()->create(['on_watchlist' => false]);

    $member->update(['on_watchlist' => true, 'watchlist_reason' => 'Rowdy', 'watchlist_review_on' => today()->addMonths(3)]);
    $member->update(['watchlist_review_on' => today()->addMonths(6)]);

    expect($member->fresh()->on_watchlist)->toBeTrue()
        ->and($member->fresh()->watchlist_review_on->toDateString())->toBe(today()->addMonths(6)->toDateString());
});

test('an Owner can take a member off the watchlist, which is logged and clears the review date', function () {
    $owner = User::factory()->create(['role' => Role::Owner]);
    actingAs($owner);
    $member = watchlisted(['watchlist_review_on' => today()]);

    $member->update(['on_watchlist' => false]);

    $change = MemberStatusChange::where('member_id', $member->id)->firstOrFail();
    expect($member->fresh()->on_watchlist)->toBeFalse()
        ->and($member->fresh()->watchlist_review_on)->toBeNull()
        ->and($change->status)->toBe(MemberStatusField::Watchlist)
        ->and($change->value)->toBeFalse()
        ->and($change->changed_by)->toBe($owner->id);
});

test('a console-driven watchlist removal is allowed and writes no audit row', function () {
    $member = watchlisted();

    $member->update(['on_watchlist' => false]);

    expect($member->fresh()->on_watchlist)->toBeFalse()
        ->and(MemberStatusChange::where('member_id', $member->id)->exists())->toBeFalse();
});

test('the resolve-watchlist gate is Owner only', function (Role $role, bool $allowed) {
    expect(Gate::forUser(User::factory()->create(['role' => $role]))->allows('resolve-watchlist'))->toBe($allowed);
})->with([
    [Role::Manager, false],
    [Role::Admin, false],
    [Role::Owner, true],
]);

// ---- Owner review decisions -----------------------------------------------

test('removing with probation clears the watchlist, starts probation, and records the review', function () {
    MembershipSetting::current()->update(['watchlist_probation_mode' => 'custom', 'watchlist_probation_days' => 90]);
    $owner = User::factory()->create(['role' => Role::Owner]);
    actingAs($owner);
    $member = watchlisted(['watchlist_review_on' => today()]);

    $member->resolveWatchlistReview($owner, WatchlistReviewDecision::Removed, startProbation: true, notes: 'Clean for a year');

    $member->refresh();
    $review = WatchlistReview::where('member_id', $member->id)->sole();
    expect($member->on_watchlist)->toBeFalse()
        ->and($member->watchlist_probation_start->toDateString())->toBe(today()->toDateString())
        ->and($member->isOnWatchlistProbation())->toBeTrue()
        ->and($review->decision)->toBe(WatchlistReviewDecision::Removed)
        ->and($review->previous_review_on->toDateString())->toBe(today()->toDateString())
        ->and($review->probation_started)->toBeTrue()
        ->and($review->notes)->toBe('Clean for a year')
        ->and($review->decided_by)->toBe($owner->id);
});

test('removing with watchlist probation off starts no probation', function () {
    MembershipSetting::current()->update(['watchlist_probation_mode' => 'off']);
    $owner = User::factory()->create(['role' => Role::Owner]);
    actingAs($owner);
    $member = watchlisted();

    $member->resolveWatchlistReview($owner, WatchlistReviewDecision::Removed, startProbation: true);

    expect($member->fresh()->watchlist_probation_start)->toBeNull()
        ->and(WatchlistReview::sole()->probation_started)->toBeFalse();
});

test('extending moves the review date and requires a future date', function () {
    $owner = User::factory()->create(['role' => Role::Owner]);
    actingAs($owner);
    $member = watchlisted(['watchlist_review_on' => today()]);

    $member->resolveWatchlistReview($owner, WatchlistReviewDecision::Extended, today()->addMonths(3));

    expect($member->fresh()->on_watchlist)->toBeTrue()
        ->and($member->fresh()->watchlist_review_on->toDateString())->toBe(today()->addMonths(3)->toDateString())
        ->and(WatchlistReview::sole()->new_review_on->toDateString())->toBe(today()->addMonths(3)->toDateString());

    expect(fn () => $member->resolveWatchlistReview($owner, WatchlistReviewDecision::Extended, today()))
        ->toThrow(HttpException::class);
});

test('keeping indefinitely clears the review date and keeps the member on', function () {
    $owner = User::factory()->create(['role' => Role::Owner]);
    actingAs($owner);
    $member = watchlisted(['watchlist_review_on' => today()]);

    $member->resolveWatchlistReview($owner, WatchlistReviewDecision::KeptIndefinitely);

    expect($member->fresh()->on_watchlist)->toBeTrue()
        ->and($member->fresh()->watchlist_review_on)->toBeNull()
        ->and($member->fresh()->isWatchlistReviewDue())->toBeFalse();
});

test('the Owner resolves a review from the member edit page', function () {
    MembershipSetting::current()->update(['watchlist_probation_mode' => 'custom', 'watchlist_probation_days' => 30]);
    actingAs(User::factory()->create(['role' => Role::Owner]));
    $member = watchlisted(['watchlist_review_on' => today()]);

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
        ->callAction('reviewWatchlist', data: [
            'decision' => WatchlistReviewDecision::Removed->value,
            'start_probation' => true,
        ])
        ->assertHasNoActionErrors();

    expect($member->fresh()->on_watchlist)->toBeFalse()
        ->and($member->fresh()->isOnWatchlistProbation())->toBeTrue();
});

test('a Manager does not get the review action', function () {
    actingAs(User::factory()->create(['role' => Role::Manager]));
    $member = watchlisted(['watchlist_review_on' => today()]);

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
        ->assertActionHidden('reviewWatchlist');

    expect($member->fresh()->on_watchlist)->toBeTrue();
});

test('a watchlist review can never be updated or deleted, even by an Owner', function () {
    $owner = User::factory()->create(['role' => Role::Owner]);
    $review = WatchlistReview::factory()->create();

    expect($owner->can('create', WatchlistReview::class))->toBeFalse()
        ->and($owner->can('update', $review))->toBeFalse()
        ->and($owner->can('delete', $review))->toBeFalse()
        ->and(User::factory()->create(['role' => Role::Manager])->can('viewAny', WatchlistReview::class))->toBeTrue();
});

// ---- Watchlist probation --------------------------------------------------

test('watchlist probation lasts the configured number of days', function () {
    MembershipSetting::current()->update(['watchlist_probation_mode' => 'custom', 'watchlist_probation_days' => 30]);

    $inside = Member::factory()->create(['watchlist_probation_start' => today()->subDays(29)]);
    $past = Member::factory()->create(['watchlist_probation_start' => today()->subDays(30)]);

    expect($inside->isOnWatchlistProbation())->toBeTrue()
        ->and($inside->watchlistProbationEndsOn()->toDateString())->toBe(today()->addDay()->toDateString())
        ->and($past->isOnWatchlistProbation())->toBeFalse();
});

test('watchlist probation off means nobody is on it', function () {
    MembershipSetting::current()->update(['watchlist_probation_mode' => 'off']);
    $member = Member::factory()->create(['watchlist_probation_start' => today()]);

    expect($member->isOnWatchlistProbation())->toBeFalse();
});

test('custom mode with no length set means nobody is on watchlist probation', function () {
    MembershipSetting::current()->update(['watchlist_probation_mode' => 'custom', 'watchlist_probation_days' => null]);
    $member = Member::factory()->create(['watchlist_probation_start' => today()]);

    expect($member->isOnWatchlistProbation())->toBeFalse();
});

test('by default watchlist probation is the same length as new-member probation, and follows it', function () {
    MembershipSetting::current()->update(['probation_period_days' => 60, 'watchlist_probation_days' => 7]);
    $member = Member::factory()->create(['watchlist_probation_start' => today()]);

    expect($member->watchlistProbationEndsOn()->toDateString())->toBe(today()->addDays(60)->toDateString());

    MembershipSetting::current()->update(['probation_period_days' => 90]);

    expect($member->watchlistProbationEndsOn()->toDateString())->toBe(today()->addDays(90)->toDateString());
});

test('by default watchlist probation follows the new-member guest rule', function () {
    MembershipSetting::current()->update(['guests_enabled' => true, 'guests_allowed_during_probation' => false, 'watchlist_probation_blocks_guests' => false]);
    $member = Member::factory()->create(['watchlist_probation_start' => today(), 'date_vetted' => today()->subYears(2)]);

    expect($member->canSponsorGuests())->toBeFalse();

    MembershipSetting::current()->update(['guests_allowed_during_probation' => true]);

    expect($member->canSponsorGuests())->toBeTrue();
});

test('an upgraded install that set its own length keeps it as custom', function () {
    MembershipSetting::current();
    DB::table('membership_settings')->update(['watchlist_probation_mode' => 'same', 'watchlist_probation_days' => 45]);

    $migration = require database_path('migrations/2026_09_26_224017_add_watchlist_probation_mode_to_membership_settings_table.php');
    $migration->down();
    $migration->up();

    expect(MembershipSetting::current()->watchlist_probation_mode)->toBe('custom')
        ->and(MembershipSetting::watchlistProbationDays())->toBe(45);
});

test('the review action hides "start probation" when watchlist probation is off', function () {
    MembershipSetting::current()->update(['watchlist_probation_mode' => 'off']);
    actingAs(User::factory()->create(['role' => Role::Owner]));
    $member = watchlisted();

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
        ->mountAction('reviewWatchlist')
        ->assertFormFieldHidden('start_probation');
});

test('a member put back on the watchlist is no longer on watchlist probation', function () {
    MembershipSetting::current()->update(['watchlist_probation_mode' => 'custom', 'watchlist_probation_days' => 30]);
    $member = watchlisted(['watchlist_probation_start' => today()]);

    expect($member->isOnWatchlistProbation())->toBeFalse();
});

test('watchlist probation blocks guest sponsoring only when the club turns that on', function () {
    MembershipSetting::current()->update(['watchlist_probation_mode' => 'custom', 'watchlist_probation_days' => 30, 'guests_enabled' => true]);
    $member = Member::factory()->create(['watchlist_probation_start' => today(), 'date_vetted' => today()->subYears(2)]);

    expect($member->canSponsorGuests())->toBeTrue();

    MembershipSetting::current()->update(['watchlist_probation_blocks_guests' => true]);

    expect($member->canSponsorGuests())->toBeFalse();
});
