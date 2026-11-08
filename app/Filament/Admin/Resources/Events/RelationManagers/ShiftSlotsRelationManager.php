<?php

namespace App\Filament\Admin\Resources\Events\RelationManagers;

use App\Filament\Concerns\ReadOnlyWhenEventArchived;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftSlot;
use App\Services\ShiftSlotService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The staff shifts this event needs. New events get their type's template
 * (ShiftSlotService); Admin+ adjusts them here for a one-off.
 */
class ShiftSlotsRelationManager extends RelationManager
{
    use ReadOnlyWhenEventArchived;
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'shiftSlots';

    protected static ?string $title = 'Staff shifts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return parent::canViewForRecord($ownerRecord, $pageClass) && MembershipSetting::current()->staff_scheduling_enabled;
    }

    public function form(Schema $schema): Schema
    {
        $event = $this->getOwnerRecord();

        return $schema
            ->components([
                Select::make('shift_role_id')
                    ->label(__('Shift role'))
                    ->relationship('shiftRole', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('active', true)->orderBy('sort_order'))
                    ->required(),
                TextInput::make('headcount')
                    ->label(__('People needed'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(50)
                    ->default(1),
                DateTimePicker::make('starts_at')
                    ->label(__('Starts'))
                    ->seconds(false)
                    ->required()
                    ->default($event instanceof Event ? $event->starts_at : null),
                DateTimePicker::make('ends_at')
                    ->label(__('Ends'))
                    ->seconds(false)
                    ->required()
                    ->after('starts_at')
                    ->default($event instanceof Event ? $event->ends_at : null),
                TextInput::make('notes')
                    ->label(__('Notes'))
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('shiftRole.name')
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('shiftRole.name')
                    ->label(__('Shift role'))
                    ->description(fn (ShiftSlot $record): ?string => $record->notes),
                TextColumn::make('starts_at')
                    ->label(__('Starts'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label(__('Ends'))
                    ->dateTime(),
                TextColumn::make('headcount')
                    ->label(__('People needed'))
                    ->numeric(),
            ])
            ->headerActions([
                $this->fillFromTemplateAction(),
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Adds whatever the event type's template has that this event doesn't
     * (ShiftSlotService skips a shift it already has), e.g. after the
     * template changes or for an event made before staff scheduling was on.
     */
    protected function fillFromTemplateAction(): Action
    {
        return Action::make('fillFromTemplate')
            ->label(__('Add shifts from the template'))
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->visible(fn (): bool => ! $this->isReadOnly()
                && Gate::allows('create', ShiftSlot::class)
                && $this->getOwnerRecord()->starts_at !== null
                && $this->getOwnerRecord()->eventType?->shiftTemplates()->exists())
            ->action(function (): void {
                abort_if($this->isReadOnly(), 403);
                abort_unless(Gate::allows('create', ShiftSlot::class), 403);

                $added = app(ShiftSlotService::class)->fillFromTemplate($this->getOwnerRecord());

                Notification::make()
                    ->title($added > 0
                        ? trans_choice('Added :count shift|Added :count shifts', $added)
                        : __('This event already has every shift in the template.'))
                    ->success()
                    ->send();
            });
    }
}
