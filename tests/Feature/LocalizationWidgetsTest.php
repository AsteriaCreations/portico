<?php

use App\Enums\Role;
use App\Filament\Admin\Widgets\RecordDeparturesWidget;
use App\Filament\Admin\Widgets\RevenueByEventTypeWidget;
use App\Filament\Admin\Widgets\RevenueTrendChartWidget;
use App\Filament\Admin\Widgets\TonightOverviewWidget;
use App\Filament\Admin\Widgets\VoucherLiabilityWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// A fixture language registered in memory ("es" is a valid ICU locale, which
// Filament's money formatting needs). See LocalizationResourcesTest.
beforeEach(function () {
    app('translator')->setLoaded(['*' => ['*' => ['es' => [
        'Outstanding voucher liability' => 'xx-Voucher liability',
        "Tonight's check-ins" => "xx-Tonight's check-ins",
        'By event type — :period' => 'xx-By event type — :period', 'This week' => 'xx-This week',
        'Revenue trend' => 'xx-Revenue trend',
        ':count in the building' => 'xx-:count inside',
        ':occupancy/:capacity in the building' => 'xx-:occupancy of :capacity inside',
    ]]]]);

    $this->actingAs(User::factory()->create(['active' => true, 'role' => Role::Owner]));
});

afterEach(function () {
    app()->setLocale('en');
});

test('stat labels are translated', function () {
    app()->setLocale('es');

    Livewire::test(VoucherLiabilityWidget::class)->assertSee('xx-Voucher liability');
    Livewire::test(TonightOverviewWidget::class)->assertSee("xx-Tonight's check-ins");
});

test('a stats widget heading and a chart widget heading are translated', function () {
    app()->setLocale('es');

    // A stats widget's getHeading() is protected, so read it from inside the instance.
    $statsWidget = Livewire::test(RevenueByEventTypeWidget::class)->instance();

    expect((fn () => $this->getHeading())->call($statsWidget))->toBe('xx-By event type — xx-This week')
        ->and(Livewire::test(RevenueTrendChartWidget::class)->instance()->getHeading())->toBe('xx-Revenue trend');
});

test('the departures widget text is translated and unchanged in English', function () {
    Livewire::test(RecordDeparturesWidget::class)->assertSee('in the building');

    app()->setLocale('es');

    Livewire::test(RecordDeparturesWidget::class)->assertSee('xx-0 inside', false)->assertDontSee('in the building');
});
