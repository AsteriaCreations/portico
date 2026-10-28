<?php

use App\Enums\AddOnKind;
use App\Enums\Role;
use App\Filament\Admin\Pages\CheckIn;
use App\Filament\Admin\Resources\IssueReports\IssueReportResource;
use App\Filament\Admin\Resources\IssueReports\Pages\ViewIssueReport;
use App\Models\AddOn;
use App\Models\Category;
use App\Models\Event;
use App\Models\IssueReport;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\User;
use App\Services\IssueReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * "Report a problem" on the Check-In Desk (IssueReportService): a snapshot
 * of the desk with personal details left out, Manager+ to read, the
 * pseudonym matched back only by an Owner. Flag issue_reports_enabled.
 */
beforeEach(function () {
    $this->irregular = Category::factory()->create(['name' => 'Irregular', 'is_comped' => false]);
    $this->door = User::factory()->create(['active' => true, 'role' => Role::Door]);
    $this->actingAs($this->door);

    $entry = AddOn::create(['name' => AddOn::ENTRY_NAME, 'kind' => AddOnKind::Entry, 'subscribable' => true]);
    Plan::create(['add_on_id' => $entry->id, 'price' => 60, 'credit' => 25, 'effective_from' => '2026-01-01']);

    MembershipSetting::current()->update(['issue_reports_enabled' => true]);

    $this->member = Member::factory()->create([
        'category_id' => $this->irregular->id,
        'username' => 'velvetfox',
        'preferred_name' => 'Velvet',
        'first_name' => 'Patricia',
        'last_name' => 'Quill',
        'email' => 'patricia.quill@example.com',
        'dob' => now()->subYears(30)->subDays(10)->toDateString(),
        'on_watchlist' => true,
        'watchlist_reason' => 'Secret watchlist reason',
        'notes' => 'Private staff note',
        'is_banned' => false,
    ]);

    $this->event = Event::factory()->create(['event_date' => now()->toDateString(), 'entry_fee' => 40, 'pool_fee' => 0]);
});

test('the desk saves a snapshot with no personal details in it', function () {
    Livewire::test(CheckIn::class)
        ->fillForm(['event_id' => $this->event->id, 'member_id' => $this->member->id])
        ->callAction('reportProblem', data: ['note' => 'Due showed $40 but Velvet has a subscription'])
        ->assertHasNoActionErrors();

    $report = IssueReport::sole();
    $json = json_encode($report->snapshot).$report->note;

    expect($report->reported_by)->toBe($this->door->id)
        ->and($report->note)->toBe('Due showed $40 but [member] has a subscription')
        ->and($report->snapshot['member']['ref'])->toBe(app(IssueReportService::class)->memberRef($this->member->id))
        ->and($report->snapshot['member']['age_years'])->toBe(30)
        ->and($report->snapshot['member']['on_watchlist'])->toBeTrue()
        ->and($report->snapshot['admission']['outcome'])->toBe('warn')
        ->and($report->snapshot['admission']['had_reason'])->toBeTrue()
        ->and($report->snapshot['event']['id'])->toBe($this->event->id)
        ->and($report->snapshot['price_before_choices']['amount_paid_cents'])->toBe(4000)
        ->and($report->snapshot['staff_role'])->toBe('door')
        ->and($report->snapshot['settings']['issue_reports_enabled'])->toBeTrue();

    foreach (['velvetfox', 'Velvet', 'Patricia', 'Quill', 'patricia.quill', 'Secret watchlist reason', 'Private staff note', $this->member->dob->toDateString()] as $personal) {
        expect($json)->not->toContain($personal);
    }

    expect(json_encode($report->snapshot))->not->toContain('"member_id"');
});

test('a voucher payer becomes a pseudonym and the voucher reason is left out', function () {
    $payer = Member::factory()->create(['category_id' => $this->irregular->id]);

    app(IssueReportService::class)->reportFromDesk(
        staff: $this->door,
        note: 'Voucher wrong',
        member: $this->member,
        event: $this->event,
        pricingData: ['apply_voucher' => true, 'voucher_payer_id' => $payer->id, 'voucher_reason' => 'Gift from Patricia', 'some_new_field' => 'x'],
        livePrice: null,
        desk: [],
    );

    $selections = IssueReport::sole()->snapshot['selections'];

    expect($selections['voucher_payer_id'])->toBe(app(IssueReportService::class)->memberRef($payer->id))
        ->and($selections['voucher_reason_filled'])->toBeTrue()
        ->and($selections)->not->toHaveKey('voucher_reason')
        ->and($selections['_left_out'])->toBe(['some_new_field']);
});

test('the desk can report with nothing picked', function () {
    Livewire::test(CheckIn::class)
        ->callAction('reportProblem', data: ['note' => 'Event list is empty'])
        ->assertHasNoActionErrors();

    expect(IssueReport::sole()->snapshot['member'])->toBeNull();
});

test('the button is hidden and refused with the flag off', function () {
    MembershipSetting::current()->update(['issue_reports_enabled' => false]);

    Livewire::test(CheckIn::class)->assertActionHidden('reportProblem');

    expect(IssueReport::count())->toBe(0);
});

test('a note is required', function () {
    Livewire::test(CheckIn::class)
        ->callAction('reportProblem', data: ['note' => ''])
        ->assertHasActionErrors(['note' => 'required']);
});

test('Manager+ can read reports, Door cannot, and nobody can change them', function () {
    $report = IssueReport::factory()->create();
    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);

    $this->actingAs($this->door)->get(IssueReportResource::getUrl('index'))->assertForbidden();
    $this->actingAs($manager)->get(IssueReportResource::getUrl('index'))->assertOk();
    $this->actingAs($manager)->get(IssueReportResource::getUrl('view', ['record' => $report]))->assertOk();

    $owner = User::factory()->create(['active' => true, 'role' => Role::Owner]);

    expect($owner->can('update', $report))->toBeFalse()
        ->and($owner->can('delete', $report))->toBeFalse()
        ->and($owner->can('create', IssueReport::class))->toBeFalse();
});

test('only an Owner can match the pseudonym back to the member', function () {
    app(IssueReportService::class)->reportFromDesk($this->door, 'x', $this->member, $this->event, [], null, []);
    $report = IssueReport::sole();

    $manager = User::factory()->create(['active' => true, 'role' => Role::Manager]);
    $this->actingAs($manager);
    Livewire::test(ViewIssueReport::class, ['record' => $report->getKey()])->assertActionHidden('findMember');

    $owner = User::factory()->create(['active' => true, 'role' => Role::Owner]);
    $this->actingAs($owner);
    Livewire::test(ViewIssueReport::class, ['record' => $report->getKey()])
        ->callAction('findMember')
        ->assertRedirect();

    expect(app(IssueReportService::class)->findMemberByRef($report->snapshot['member']['ref'])?->id)->toBe($this->member->id)
        ->and(app(IssueReportService::class)->findMemberByRef('m_0000000000000000'))->toBeNull();
});
