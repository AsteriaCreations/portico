<?php

use App\Enums\AdmissionOutcome;
use App\Models\BanException;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Services\AdmissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->policy = new AdmissionPolicy;
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->prospective = Category::factory()->create(['name' => 'Prospective', 'is_comped' => false]);
});

function memberAgedAsOf(Category $category, string $dob, array $overrides = []): Member
{
    return Member::factory()->create(array_merge([
        'category_id' => $category->id,
        'dob' => $dob,
        'is_banned' => false,
        'on_watchlist' => false,
        'first_name' => 'Pat',
        'last_name' => 'Doe',
        'email' => 'pat@example.com',
    ], $overrides));
}

test('a banned member is blocked with the ban reason', function () {
    $member = memberAgedAsOf($this->irregular, '1990-01-01', [
        'is_banned' => true,
        'ban_reason' => 'Fought at the bar',
    ]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Block)
        ->and($decision->message)->toBe('Do not admit')
        ->and($decision->reason)->toBe('Fought at the bar')
        ->and($decision->blocksCheckIn())->toBeTrue();
});

test('a member under 18 as of the event date is blocked', function () {
    $member = memberAgedAsOf($this->irregular, '2009-01-01'); // 17 on 2026-07-19
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Block)
        ->and($decision->message)->toBe('Under 18 — no admittance');
});

test('a watchlisted adult is warned with the watchlist reason', function () {
    $member = memberAgedAsOf($this->irregular, '1990-01-01', [
        'on_watchlist' => true,
        'watchlist_reason' => 'Prior incident, verify with manager',
    ]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Warn)
        ->and($decision->message)->toBe('Notify the staff channel')
        ->and($decision->reason)->toBe('Prior incident, verify with manager')
        ->and($decision->requiresAcknowledgement())->toBeTrue();
});

test('the watchlist warning message uses the configured notify-channel label', function () {
    config(['membership.watchlist_notify_label' => 'the Signal group']);

    $member = memberAgedAsOf($this->irregular, '1990-01-01', [
        'on_watchlist' => true,
        'watchlist_reason' => 'Prior incident',
    ]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->message)->toBe('Notify the Signal group');
});

test('a prospective member with incomplete identity requires capture', function () {
    $member = memberAgedAsOf($this->prospective, '1990-01-01', ['first_name' => null]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Capture)
        ->and($decision->message)->toBe('Complete sign-up');
});

test('a prospective member with complete identity does not require capture', function () {
    $member = memberAgedAsOf($this->prospective, '1990-01-01');
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Ok);
});

test('an 18-20 year old with no other flags is flagged but admitted', function () {
    $member = memberAgedAsOf($this->irregular, '2007-08-01'); // 18 on 2026-07-19
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Flag)
        ->and($decision->message)->toBe('Under 21 — no alcohol, mark hand')
        ->and($decision->blocksCheckIn())->toBeFalse();
});

test('a clear adult member is cleared', function () {
    $member = memberAgedAsOf($this->irregular, '1990-01-01');
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Ok)
        ->and($decision->message)->toBe('Cleared');
});

test('banned takes precedence over under-18', function () {
    $member = memberAgedAsOf($this->irregular, '2015-01-01', [
        'is_banned' => true,
        'ban_reason' => 'Banned reason',
    ]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Block)
        ->and($decision->message)->toBe('Do not admit');
});

test('banned takes precedence over watchlist', function () {
    $member = memberAgedAsOf($this->irregular, '1990-01-01', [
        'is_banned' => true,
        'ban_reason' => 'Banned reason',
        'on_watchlist' => true,
        'watchlist_reason' => 'Should not surface',
    ]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Block)
        ->and($decision->reason)->toBe('Banned reason');
});

test('a missing dob skips the age checks without error', function () {
    $member = memberAgedAsOf($this->irregular, '1990-01-01', ['dob' => null]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Ok);
});

test('age is resolved as of the event date, not today, for a future prepaid event', function () {
    // 17 today, turns 18 well before the event.
    $member = memberAgedAsOf($this->irregular, now()->subYears(17)->toDateString());
    $event = Event::factory()->create(['event_date' => now()->addYear()->toDateString()]);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->not->toBe(AdmissionOutcome::Block);
});

test('a deceased member is blocked, taking precedence over everything else', function () {
    $member = memberAgedAsOf($this->irregular, '1990-01-01', ['is_deceased' => true]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Block)
        ->and($decision->message)->toBe('Member marked deceased');
});

test('a banned member with a granted exception for this event is warned, not blocked', function () {
    $member = memberAgedAsOf($this->irregular, '1990-01-01', [
        'is_banned' => true,
        'ban_reason' => 'Banned reason',
    ]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    BanException::factory()->create(['member_id' => $member->id, 'event_id' => $event->id]);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Warn)
        ->and($decision->message)->toBe('Banned — one-time exception granted for this event')
        ->and($decision->reason)->toBe('Banned reason')
        ->and($decision->requiresAcknowledgement())->toBeTrue();
});

test('a banned member with an exception for a different event is still blocked', function () {
    $member = memberAgedAsOf($this->irregular, '1990-01-01', [
        'is_banned' => true,
        'ban_reason' => 'Banned reason',
    ]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    $otherEvent = Event::factory()->create(['event_date' => '2026-08-01']);
    BanException::factory()->create(['member_id' => $member->id, 'event_id' => $otherEvent->id]);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Block)
        ->and($decision->message)->toBe('Do not admit');
});

test('a member missing paperwork requires capture', function () {
    $member = memberAgedAsOf($this->irregular, '1990-01-01', ['missing_paperwork' => true]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Capture)
        ->and($decision->message)->toBe('Missing paperwork — confirm on file before check-in');
});

test('a prospective member with incomplete identity is captured ahead of a missing-paperwork flag', function () {
    $member = memberAgedAsOf($this->prospective, '1990-01-01', ['first_name' => null, 'missing_paperwork' => true]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Capture)
        ->and($decision->message)->toBe('Complete sign-up');
});

test('a permanent ban (no banned_until) still blocks indefinitely', function () {
    $member = memberAgedAsOf($this->irregular, '1990-01-01', [
        'is_banned' => true,
        'ban_reason' => 'Banned reason',
        'banned_until' => null,
    ]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Block);
});

test('a suspension with a future banned_until still blocks', function () {
    $member = memberAgedAsOf($this->irregular, '1990-01-01', [
        'is_banned' => true,
        'ban_reason' => 'Suspended',
        'banned_until' => now()->addMonth()->toDateString(),
    ]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Block);
});

test('a suspension with a past banned_until no longer blocks', function () {
    $member = memberAgedAsOf($this->irregular, '1990-01-01', [
        'is_banned' => true,
        'ban_reason' => 'Suspended',
        'banned_until' => now()->subMonth()->toDateString(),
    ]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->not->toBe(AdmissionOutcome::Block);
});

test('a ban exception still downgrades an active suspension to a warn, same as a permanent ban', function () {
    $member = memberAgedAsOf($this->irregular, '1990-01-01', [
        'is_banned' => true,
        'ban_reason' => 'Suspended',
        'banned_until' => now()->addMonth()->toDateString(),
    ]);
    $event = Event::factory()->create(['event_date' => '2026-07-19']);
    BanException::factory()->create(['member_id' => $member->id, 'event_id' => $event->id]);

    $decision = $this->policy->decide($member, $event);

    expect($decision->outcome)->toBe(AdmissionOutcome::Warn)
        ->and($decision->requiresAcknowledgement())->toBeTrue();
});
