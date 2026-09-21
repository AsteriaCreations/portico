<?php

use App\Enums\Role;
use App\Filament\Admin\Widgets\MonthlyRevenueChartWidget;
use App\Filament\Admin\Widgets\RecordDeparturesWidget;
use App\Filament\Admin\Widgets\TonightOverviewWidget;
use App\Filament\Admin\Widgets\VoucherLiabilityWidget;
use App\Filament\Admin\Widgets\WeeklySummaryWidget;
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
        'This Week' => 'xx-This Week',
        'Monthly Revenue' => 'xx-Monthly Revenue',
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
    $statsWidget = Livewire::test(WeeklySummaryWidget::class)->instance();

    expect((fn () => $this->getHeading())->call($statsWidget))->toBe('xx-This Week')
        ->and(Livewire::test(MonthlyRevenueChartWidget::class)->instance()->getHeading())->toBe('xx-Monthly Revenue');
});

test('the departures widget text is translated and unchanged in English', function () {
    Livewire::test(RecordDeparturesWidget::class)->assertSee('in the building');

    app()->setLocale('es');

    Livewire::test(RecordDeparturesWidget::class)->assertSee('xx-0 inside', false)->assertDontSee('in the building');
});
