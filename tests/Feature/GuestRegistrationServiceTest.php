<?php

use App\Exceptions\GuestRefused;
use App\Models\Attendance;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Services\GuestRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new GuestRegistrationService;
    $irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->guestCategory = Category::factory()->create(['name' => 'Guest', 'is_comped' => false]);
    $this->sponsor = Member::factory()->create(['category_id' => $irregular->id, 'dob' => '1990-01-01']);
    // An earlier arrival, so tonight isn't their first entry (no probation).
    Attendance::factory()->for($this->sponsor)->for(Event::factory()->create(['event_date' => today()->subYear()->toDateString()]))->create(['checked_in_at' => now()->subYear()]);
    $this->event = Event::factory()->create(['event_date' => today()->toDateString()]);
    $this->visit = Attendance::factory()->for($this->sponsor)->for($this->event)->create(['checked_in_at' => now()]);
});

function guestDetails(string $username = 'newguest'): array
{
    return ['username' => $username, 'preferred_name' => 'New', 'first_name' => 'New', 'last_name' => 'Guest', 'email' => ''];
}

test('register creates a Guest member sponsored by the host, with a blank email stored as null', function () {
    $guest = $this->service->register($this->sponsor, $this->visit, guestDetails());

    expect($guest->category_id)->toBe($this->guestCategory->id)
        ->and($guest->sponsor_id)->toBe($this->sponsor->id)
        ->and($guest->email)->toBeNull()
        ->and($guest->notes)->toBe("Guest of {$this->sponsor->displayName()}.");
});

test('a host with a visit row but no arrival cannot register a guest, even with no guest limit set', function () {
    MembershipSetting::current()->update(['max_guests_per_night' => null]);
    $this->visit->update(['checked_in_at' => null]);

    expect($this->service->canRegister($this->sponsor, $this->visit))->toBeFalse();
    expect(fn () => $this->service->register($this->sponsor, $this->visit, guestDetails()))
        ->toThrow(HttpException::class);
    expect(Member::where('username', 'newguest')->exists())->toBeFalse();
});

test('another member\'s visit cannot be used to sponsor a guest', function () {
    $someoneElse = Member::factory()->create(['dob' => '1990-01-01']);

    expect($this->service->canRegister($someoneElse, $this->visit))->toBeFalse();
    expect(fn () => $this->service->register($someoneElse, $this->visit, guestDetails()))
        ->toThrow(HttpException::class);
});

test('the guest limit and a taken username are refused with a message, saving nothing', function () {
    MembershipSetting::current()->update(['max_guests_per_night' => 1]);
    Member::factory()->create(['username' => 'taken']);

    expect(fn () => $this->service->register($this->sponsor, $this->visit, guestDetails('taken')))
        ->toThrow(GuestRefused::class, 'That username was just taken');

    $this->service->register($this->sponsor, $this->visit, guestDetails('first'));

    expect($this->service->canRegister($this->sponsor, $this->visit))->toBeFalse();
    expect(fn () => $this->service->register($this->sponsor, $this->visit, guestDetails('second')))
        ->toThrow(GuestRefused::class, 'Guest limit reached');
    expect(Member::where('sponsor_id', $this->sponsor->id)->count())->toBe(1);
});
