<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Event;
use App\Models\EventTimelineCategory;
use App\Models\EventTimelineItem;
use App\Models\User;
use App\Services\EventTimelineService;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * An event's run of show as a day view bounded by its starts_at/ends_at:
 * click the grid to add an item, click an item to change it. The event's
 * showrunner (while it's current or upcoming) and Admin+ edit it; every
 * rule lives in EventTimelineService.
 */
class EventTimeline extends Page
{
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.event-timeline';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Event timeline';

    protected static ?string $title = 'Event timeline';

    protected static string|UnitEnum|null $navigationGroup = 'Front of House';

    protected static ?int $navigationSort = 5;

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /** Show (and print) only the Public categories, as attendees see it. */
    public bool $publicOnly = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(EventTimelineService::class)->canAccessPage($user);
    }

    public function mount(): void
    {
        $requested = request()->integer('event') ?: null;

        $this->form->fill([
            'event_id' => $requested && $this->eventIfAllowed($requested)
                ? $requested
                : $this->defaultEventId(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('event_id')
                    ->label(__('Event'))
                    ->live()
                    ->options(fn (): array => $this->eventOptions())
                    ->placeholder(__('Pick an event')),
            ]);
    }

    /**
     * The picked event, re-checked against what this user may see each
     * time, never trusted from the submitted id alone.
     */
    public function getSelectedEvent(): ?Event
    {
        $id = $this->data['event_id'] ?? null;

        return $id ? $this->eventIfAllowed((int) $id) : null;
    }

    public function canEditSelected(): bool
    {
        $event = $this->getSelectedEvent();

        return $event !== null && $this->timelines()->canEdit(auth()->user(), $event);
    }

    /**
     * @return array{slotCount: int, hours: list<array<string, mixed>>, slots: list<array<string, mixed>>, blocks: list<array<string, mixed>>}|null
     */
    public function grid(): ?array
    {
        $event = $this->getSelectedEvent();

        return $event ? $this->timelines()->layout($event, $this->items()) : null;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, EventTimelineItem>
     */
    public function items(): \Illuminate\Database\Eloquent\Collection
    {
        $event = $this->getSelectedEvent();

        return $event ? $this->timelines()->itemsFor($event, $this->audience()) : new \Illuminate\Database\Eloquent\Collection;
    }

    /**
     * The categories shown: what this user sees, or Public only.
     *
     * @return Collection<int, EventTimelineCategory>
     */
    public function legend(): Collection
    {
        return $this->timelines()->visibleCategoriesQuery($this->audience(), $this->getSelectedEvent())->get();
    }

    /**
     * The categories an editor can put an item in: every one they see,
     * whatever the Public only view shows.
     *
     * @return array<int, string>
     */
    protected function categoryOptions(): array
    {
        return $this->timelines()->visibleCategoriesQuery(auth()->user(), $this->getSelectedEvent())->pluck('name', 'id')->all();
    }

    /** Whose eyes the timeline is shown through: null = an attendee's. */
    protected function audience(): ?User
    {
        return $this->publicOnly ? null : auth()->user();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('togglePublicOnly')
                ->label(fn (): string => $this->publicOnly ? __('Show everything I can see') : __('Show public only'))
                ->icon(fn (): Heroicon => $this->publicOnly ? Heroicon::OutlinedEye : Heroicon::OutlinedUserGroup)
                ->color('gray')
                ->visible(fn (): bool => $this->getSelectedEvent() !== null)
                ->action(fn () => $this->publicOnly = ! $this->publicOnly),
            Action::make('print')
                ->label(__('Print'))
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (): bool => $this->getSelectedEvent() !== null)
                ->alpineClickHandler('window.print()'),
        ];
    }

    public function addItemAction(): Action
    {
        return Action::make('addItem')
            ->label(__('Add item'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(__('Add to the timeline'))
            ->modalSubmitActionLabel(__('Add'))
            ->visible(fn (): bool => $this->canEditSelected())
            ->fillForm(function (array $arguments): array {
                $event = $this->getSelectedEvent();
                $at = isset($arguments['at']) ? Carbon::parse($arguments['at']) : $event?->starts_at?->copy();
                $end = $at?->copy()->addMinutes(30);

                return [
                    'event_timeline_category_id' => array_key_first($this->categoryOptions()),
                    'starts_at' => $at?->format('Y-m-d H:i:s'),
                    'ends_at' => $end && $event?->ends_at && $end->gt($event->ends_at) ? $event->ends_at->format('Y-m-d H:i:s') : $end?->format('Y-m-d H:i:s'),
                ];
            })
            ->schema(fn (): array => $this->itemFormSchema())
            ->action(function (array $data): void {
                $this->timelines()->save(auth()->user(), $this->requireEditableEvent(), $data);

                Notification::make()->title(__('Added to the timeline'))->success()->send();
            });
    }

    public function editItemAction(): Action
    {
        return Action::make('editItem')
            ->modalHeading(__('Change timeline item'))
            ->modalSubmitActionLabel(__('Save'))
            ->visible(fn (): bool => $this->canEditSelected())
            ->fillForm(fn (array $arguments): array => ($item = $this->itemFromArguments($arguments)) ? [
                'event_timeline_category_id' => $item->event_timeline_category_id,
                'title' => $item->title,
                'description' => $item->description,
                'starts_at' => $item->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $item->ends_at?->format('Y-m-d H:i:s'),
            ] : [])
            ->schema(fn (): array => $this->itemFormSchema())
            ->extraModalFooterActions(fn (array $arguments): array => [
                Action::make('deleteItem')
                    ->label(__('Delete'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Delete this timeline item?'))
                    ->action(function (Action $action) use ($arguments): void {
                        $this->requireEditableEvent();
                        $item = $this->itemFromArguments($arguments);
                        abort_unless($item !== null, 404);

                        $this->timelines()->delete(auth()->user(), $item);
                        Notification::make()->title(__('Removed from the timeline'))->success()->send();
                        $action->cancelParentActions();
                    }),
            ])
            ->action(function (array $data, array $arguments): void {
                $event = $this->requireEditableEvent();
                $item = $this->itemFromArguments($arguments);
                abort_unless($item !== null, 404);

                $this->timelines()->save(auth()->user(), $event, $data, $item);
                Notification::make()->title(__('Timeline item saved'))->success()->send();
            });
    }

    /**
     * @return array<int, Component|Field>
     */
    protected function itemFormSchema(): array
    {
        $event = $this->getSelectedEvent();
        $timeRule = fn (string $field): Closure => function (Get $get) use ($event, $field): Closure {
            return function (string $attribute, mixed $value, Closure $fail) use ($get, $event, $field): void {
                if (! $event) {
                    $fail(__('Pick an event first.'));

                    return;
                }

                $startsAt = filled($get('starts_at')) ? Carbon::parse($get('starts_at')) : null;
                $endsAt = filled($get('ends_at')) ? Carbon::parse($get('ends_at')) : null;
                $error = $this->timelines()->timeError($event, $startsAt, $endsAt);

                // Report once, on the field it's about.
                $aboutEnd = $error !== null && $endsAt !== null && ($startsAt === null || ! $this->timelines()->timeError($event, $startsAt, null));
                if ($error !== null && ($field === 'ends_at') === $aboutEnd) {
                    $fail($error);
                }
            };
        };

        return [
            TextInput::make('title')
                ->label(__('What'))
                ->placeholder(__('Doors open, Raffle draw, Reset the main room…'))
                ->required()
                ->maxLength(120),
            Select::make('event_timeline_category_id')
                ->label(__('Category'))
                ->helperText(__('Who sees it. Public items go on the printed timeline and the display screen.'))
                ->options(fn (): array => $this->categoryOptions())
                ->required(),
            DateTimePicker::make('starts_at')
                ->label(__('Starts'))
                ->seconds(false)
                ->minDate($event?->starts_at)
                ->maxDate($event?->ends_at)
                ->required()
                ->rule($timeRule('starts_at')),
            DateTimePicker::make('ends_at')
                ->label(__('Ends'))
                ->helperText(__('Leave empty for a moment rather than a span ("Raffle draw").'))
                ->seconds(false)
                ->minDate($event?->starts_at)
                ->maxDate($event?->ends_at)
                ->rule($timeRule('ends_at')),
            Textarea::make('description')
                ->label(__('Details'))
                ->rows(3)
                ->maxLength(2000),
        ];
    }

    protected function itemFromArguments(array $arguments): ?EventTimelineItem
    {
        $event = $this->getSelectedEvent();

        return $event ? EventTimelineItem::where('event_id', $event->id)->find($arguments['item'] ?? 0) : null;
    }

    protected function requireEditableEvent(): Event
    {
        $event = $this->getSelectedEvent();
        abort_unless($event && $this->timelines()->canEdit(auth()->user(), $event), 403);

        return $event;
    }

    /**
     * The soonest event this user can edit, else the soonest they can see.
     */
    protected function defaultEventId(): ?int
    {
        $user = auth()->user();
        $soonest = fn ($query) => $query->orderBy('event_date')->orderBy('starts_at')->value('id');

        return $soonest($this->timelines()->editableEventsQuery($user)) ?? $soonest($this->timelines()->viewableEventsQuery($user));
    }

    protected function eventIfAllowed(int $id): ?Event
    {
        $event = Event::find($id);

        return $event && $this->timelines()->canView(auth()->user(), $event) ? $event : null;
    }

    /**
     * @return array<int, string>
     */
    protected function eventOptions(): array
    {
        $options = $this->timelines()->viewableEventsQuery(auth()->user())
            ->orderBy('event_date')
            ->orderBy('starts_at')
            ->get()
            ->mapWithKeys(fn (Event $event): array => [$event->id => static::eventLabel($event)]);

        // A past event an Admin+ opened from its own page's Timeline link.
        if (($selected = $this->getSelectedEvent()) && ! $options->has($selected->id)) {
            $options->put($selected->id, static::eventLabel($selected));
        }

        return $options->all();
    }

    /**
     * "Timeline" on an event's own View/Edit page, for whoever may edit it.
     */
    public static function linkAction(): Action
    {
        return Action::make('timeline')
            ->label(__('Timeline'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->visible(fn (Event $record): bool => ($user = auth()->user()) !== null && app(EventTimelineService::class)->canEdit($user, $record))
            ->url(fn (Event $record): string => static::getUrl(['event' => $record->id]));
    }

    protected static function eventLabel(Event $event): string
    {
        return $event->event_date->isoFormat('ll').' — '.($event->name ?? __('Untitled event'));
    }

    protected function timelines(): EventTimelineService
    {
        return app(EventTimelineService::class);
    }
}
