<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Members\Pages\ListMembers;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    MembershipSetting::current()->update(['probation_period_days' => 90, 'subscription_eligibility_threshold' => 5, 'subscription_eligibility_window_months' => null]);
    $this->irregular = Category::firstOrCreate(['name' => 'Irregular']);
    $this->tonight = Event::factory()->create(['event_date' => today()->toDateString()]);
});

test('a member with visits from before these records is never on a first visit', function () {
    $new = Member::factory()->create(['category_id' => $this->irregular->id]);
    $returning = Member::factory()->create(['category_id' => $this->irregular->id, 'prior_visits' => 3]);

    expect($new->isFirstVisit())->toBeTrue()
        ->and($returning->isFirstVisit())->toBeFalse();
});

test('their first recorded visit is not a new-member visit', function () {
    $new = Member::factory()->create(['category_id' => $this->irregular->id]);
    $returning = Member::factory()->create(['category_id' => $this->irregular->id, 'prior_visits' => 3]);

    $newVisit = Attendance::factory()->for($new)->for($this->tonight)->create(['checked_in_at' => now()]);
    $returningVisit = Attendance::factory()->for($returning)->for($this->tonight)->create(['checked_in_at' => now()]);

    expect($newVisit->isNewMemberVisit())->toBeTrue()
        ->and($returningVisit->isNewMemberVisit())->toBeFalse();
});

test('earlier visits mean no probation, unless a manager sets an override', function () {
    $returning = Member::factory()->create(['category_id' => $this->irregular->id, 'prior_visits' => 3]);
    Attendance::factory()->for($returning)->for($this->tonight)->create(['checked_in_at' => now()]);

    $new = Member::factory()->create(['category_id' => $this->irregular->id]);
    Attendance::factory()->for($new)->for($this->tonight)->create(['checked_in_at' => now()]);

    $overridden = Member::factory()->create(['category_id' => $this->irregular->id, 'prior_visits' => 3, 'probation_override_start' => today()->subDays(10)]);

    expect($returning->isOnProbation())->toBeFalse()
        ->and($new->isOnProbation())->toBeTrue()
        ->and($overridden->isOnProbation())->toBeTrue();
});

test('earlier visits count toward subscription eligibility, but not within a window', function () {
    $member = Member::factory()->create(['category_id' => $this->irregular->id, 'prior_visits' => 4, 'subscription_eligible' => false]);
    Attendance::factory()->for($member)->for($this->tonight)->create(['checked_in_at' => now()]);

    expect($member->eligibilityAttendanceCount())->toBe(5)
        ->and($member->isSubscriptionEligible())->toBeTrue();

    MembershipSetting::current()->update(['subscription_eligibility_window_months' => 12]);

    expect($member->eligibilityAttendanceCount())->toBe(1)
        ->and($member->isSubscriptionEligible())->toBeFalse();
});

test('the members list probation filter leaves out members with earlier visits', function () {
    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Manager]));
    $returning = Member::factory()->create(['category_id' => $this->irregular->id, 'prior_visits' => 3]);
    $new = Member::factory()->create(['category_id' => $this->irregular->id]);
    foreach ([$returning, $new] as $member) {
        Attendance::factory()->for($member)->for($this->tonight)->create(['checked_in_at' => now()]);
    }

    Livewire::test(ListMembers::class)
        ->filterTable('on_probation')
        ->assertCanSeeTableRecords([$new])
        ->assertCanNotSeeTableRecords([$returning]);
});

test('the analyst view includes prior_visits', function () {
    $member = Member::factory()->create(['prior_visits' => 7]);

    expect(DB::table('analyst_members')->where('id', $member->id)->value('prior_visits'))->toBe(7);
});
