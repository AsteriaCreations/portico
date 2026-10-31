<?php

namespace App\Filament\Concerns;

use Livewire\Attributes\On;

/**
 * For an Event page's footer widgets. Each widget is its own Livewire
 * component, so removing a visit from one of the page's attendance tabs
 * redraws only that tab; without this the widget's figures (the showrunner
 * headcount, the instructor payout, the comp cost) kept counting the removed
 * visit until the page was reloaded. RemovesVisits dispatches 'visit-removed'.
 */
trait RefreshesAfterVisitRemoval
{
    /**
     * Empty on purpose: a Livewire listener call re-renders the component,
     * and these widgets compute their figures fresh on every render.
     */
    #[On('visit-removed')]
    public function refreshAfterVisitRemoval(): void {}
}
