<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Models\Event;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only foregone-revenue breakdown for this event's comp list, grouped
 * by comp_reasons — the per-event slice of what CompCostWidget already shows
 * globally per week. Scoped to attendees who actually arrived
 * (App\Filament\Admin\Resources\Events\RelationManagers\CompListRelationManager's
 * own table stays scoped to the opposite: unarrived rows only, since it's a
 * forward-looking checklist, not a cost record) with
 * EntryCoverageSource::EventComp specifically — matches CompCostWidget's own
 * reasoning for excluding Host, which isn't reason-tagged the same way.
 * Renders nothing when nothing on this event's comp list has arrived yet.
 */
class EventCompCostWidget extends Widget
{
    protected string $view = 'filament.admin.widgets.event-comp-cost-widget';

    // Not lazy: see ShowrunnerPayoutWidget's own comment on why.
    protected static bool $isLazy = false;

    public ?Event $record = null;

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    /**
     * @return Collection<int, object{name: string, comps: int, foregone: float}>|null
     */
    public function getLineItems(): ?Collection
    {
        if ($this->record === null) {
            return null;
        }

        $rows = DB::table('attendance')
            ->join('comp_reasons', 'comp_reasons.id', '=', 'attendance.comp_reason_id')
            ->where('attendance.event_id', $this->record->id)
            ->whereNotNull('attendance.checked_in_at')
            ->where('attendance.entry_covered_by', EntryCoverageSource::EventComp->value)
            ->groupBy('comp_reasons.id', 'comp_reasons.name', 'comp_reasons.sort_order')
            ->orderBy('comp_reasons.sort_order')
            ->selectRaw('comp_reasons.name as name, COUNT(*) as comps, SUM(attendance.entry_coverage) as foregone')
            ->get();

        return $rows->isEmpty() ? null : $rows;
    }
}
