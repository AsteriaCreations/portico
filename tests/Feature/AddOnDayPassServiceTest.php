<?php

use App\Enums\AddOnKind;
use App\Exceptions\DayPassRefused;
use App\Models\AddOn;
use App\Models\AddOnDayPass;
use App\Models\Category;
use App\Models\Event;
use App\Models\Member;
use App\Models\PaperworkType;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\User;
use App\Services\AddOnDayPassService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new AddOnDayPassService;
    $this->staff = User::factory()->create();
    $this->entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    $this->pool = AddOn::create(['name' => AddOn::POOL_NAME, 'subscribable' => true, 'priced_per_event' => true]);
    Plan::create(['add_on_id' => $this->entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);
    Plan::create(['add_on_id' => $this->pool->id, 'price' => 15, 'credit' => null, 'effective_from' => '2026-01-01']);

    $this->member = Member::factory()->create([
        'category_id' => Category::factory()->create(['name' => 'Irregular', 'is_comped' => false])->id,
        'dob' => '1990-01-01',
    ]);
    $this->event = Event::factory()->create(['event_date' => today()->toDateString(), 'pool_fee' => 15.10]);
});

test('sell records the pass at the event price with the transaction fee folded in, to the cent', function () {
    PaymentMethod::factory()->create(['code' => 'card', 'transaction_fee' => 0.20]);

    $pass = $this->service->sell($this->member, $this->pool, $this->event, $this->staff, paymentMethod: 'card');

    expect($pass->amount_paid)->toBe('15.30')
        ->and($pass->payment_method)->toBe('card')
        ->and($pass->recorded_by)->toBe($this->staff->id);
});

test('a one-time method is recorded on the member, and refused once already used', function () {
    PaymentMethod::factory()->create(['code' => 'once-only', 'label' => 'Once Only', 'one_time_only' => true]);

    $this->service->sell($this->member, $this->pool, $this->event, $this->staff, paymentMethod: 'once-only');
    expect($this->member->refresh()->hospitality_note)->toContain('Once Only used');

    $nextEvent = Event::factory()->create(['event_date' => today()->toDateString(), 'pool_fee' => 15]);

    expect(fn () => $this->service->sell($this->member, $this->pool, $nextEvent, $this->staff, paymentMethod: 'once-only'))
        ->toThrow(HttpException::class);
    expect(AddOnDayPass::where('event_id', $nextEvent->id)->exists())->toBeFalse();
});

test('a second pass for the same member, event and add-on is refused with a message, not a server error', function () {
    $this->service->sell($this->member, $this->pool, $this->event, $this->staff, 'comp');

    expect(fn () => $this->service->sell($this->member, $this->pool, $this->event, $this->staff, 'comp'))
        ->toThrow(DayPassRefused::class);
    expect(AddOnDayPass::count())->toBe(1);
});

test('sell refuses a flat add-on, missing paperwork, an archived event, and a later event while the flag is off', function () {
    $flat = AddOn::create(['name' => 'Towel', 'subscribable' => true, 'priced_per_event' => false]);
    expect(fn () => $this->service->sell($this->member, $flat, $this->event, $this->staff, 'comp'))->toThrow(HttpException::class);

    $archived = Event::factory()->create(['event_date' => today()->toDateString(), 'pool_fee' => 15, 'archived_at' => now()]);
    expect(fn () => $this->service->sell($this->member, $this->pool, $archived, $this->staff, 'comp'))->toThrow(HttpException::class);

    $later = Event::factory()->create(['event_date' => today()->addWeek()->toDateString(), 'pool_fee' => 15]);
    expect(fn () => $this->service->sell($this->member, $this->pool, $later, $this->staff, 'comp'))->toThrow(HttpException::class);

    PaperworkType::where('name', 'Pool Waiver')->firstOrFail()->update(['renewal_months' => 12, 'gates_add_on_id' => $this->pool->id]);
    expect(fn () => $this->service->sell($this->member->refresh(), $this->pool, $this->event, $this->staff, 'comp'))->toThrow(HttpException::class);

    expect(AddOnDayPass::count())->toBe(0);
});

test('sellableAddOns lists Pool only while some event has a pool fee and the member may use it', function () {
    expect($this->service->sellableAddOns($this->member)->pluck('id')->all())->toBe([$this->pool->id]);

    $this->event->update(['pool_fee' => 0]);
    expect($this->service->sellableAddOns($this->member))->toBeEmpty();
});
