<?php

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Events\Tables\EventsTable;
use App\Filament\Admin\Widgets\EventCompCostWidget;
use App\Filament\Admin\Widgets\InstructorPayoutWidget;
use App\Filament\Admin\Widgets\ShowrunnerPayoutWidget;
use App\Models\Event;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EventsTable::archiveAction(),
            EventsTable::unarchiveAction(),
            // EventPolicy::delete() hides this once anything is recorded
            // against the event; archive it instead.
            DeleteAction::make(),
        ];
    }

    /**
     * An archived event is read-only here: its attendance, payouts and
     * history stay visible below, but nothing about it can be changed until
     * it's unarchived.
     */
    public function form(Schema $schema): Schema
    {
        return parent::form($schema)->disabled(fn (): bool => $this->isArchived());
    }

    protected function getFormActions(): array
    {
        return $this->isArchived() ? [] : parent::getFormActions();
    }

    /**
     * The hidden Save button isn't the guard -- a forged save call still
     * reaches here.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_if($this->isArchived(), 403);

        return $data;
    }

    protected function getFooterWidgets(): array
    {
        return [
            ShowrunnerPayoutWidget::class,
            InstructorPayoutWidget::class,
            EventCompCostWidget::class,
        ];
    }

    private function isArchived(): bool
    {
        $record = $this->getRecord();

        return $record instanceof Event && $record->isArchived();
    }
}
