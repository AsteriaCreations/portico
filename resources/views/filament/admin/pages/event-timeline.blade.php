<x-filament-panels::page>
    <x-screen-instructions :title="__('How to use the Event timeline')">
        <p>{{ __('Lay out what happens when during an event, so staff (and, for public items, attendees) know what\'s coming. The grid runs from the event\'s start to its end; set those on the event itself.') }}</p>
        <p>{!! __('Click an empty spot on the grid to add an item starting there, or use <strong>Add item</strong>. Click an item to change or delete it. Leave <strong>Ends</strong> empty for a moment rather than a span.') !!}</p>
        <p>{{ __('Each item\'s category says who sees it: a Public one is for attendees, others for staff from a given role up. An Admin manages the categories.') }}</p>
        <p>{{ __('You can edit the timeline of an event you\'re the showrunner of until it ends; an Admin can edit any event\'s.') }}</p>
    </x-screen-instructions>

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @if ($event = $this->getSelectedEvent())
        @php
            $grid = $this->grid();
            $items = $this->items();
        @endphp

        <x-filament::section>
            @if ($this->canEditSelected() && $grid)
                <div style="margin-bottom:1rem;">
                    {{ $this->addItemAction }}
                </div>
            @endif

            @if ($grid)
                <ul aria-label="{{ __('Categories') }}" style="display:flex;flex-wrap:wrap;gap:.75rem;list-style:none;padding:0;margin:0 0 .75rem;font-size:.8rem;">
                    @foreach ($this->legend() as $category)
                        <li style="display:flex;align-items:center;gap:.35rem;">
                            <span aria-hidden="true" style="display:inline-block;width:.8rem;height:.8rem;border-radius:.2rem;background:{{ $category->color }};"></span>
                            {{ $category->name }}@if ($category->is_public) ({{ __('public') }})@endif
                        </li>
                    @endforeach
                </ul>

                <x-event-timeline-grid
                    :grid="$grid"
                    :editable="$this->canEditSelected()"
                    :caption="__(':event, :start – :end', ['event' => $event->name ?? __('Untitled event'), 'start' => $event->starts_at->isoFormat('llll'), 'end' => $event->ends_at->isoFormat('LT')])"
                />
            @else
                <p>{{ __('This event has no start and end time, so it can\'t have a timeline. Set them on the event first.') }}</p>
            @endif
        </x-filament::section>

        @if ($grid)
            <x-filament::section :heading="__('In order')">
                @if ($items->isEmpty())
                    <p style="opacity:.7;">{{ __('Nothing on the timeline yet.') }}</p>
                @else
                    <ol style="list-style:none;padding:0;margin:0;display:grid;gap:.5rem;">
                        @foreach ($items as $item)
                            <li style="display:flex;gap:.75rem;align-items:baseline;">
                                <span style="min-width:9rem;font-variant-numeric:tabular-nums;">
                                    {{ $item->starts_at->isoFormat('LT') }}@if ($item->ends_at) – {{ $item->ends_at->isoFormat('LT') }}@endif
                                </span>
                                <span>
                                    <span aria-hidden="true" style="display:inline-block;width:.6rem;height:.6rem;border-radius:.15rem;background:{{ $item->category->color }};"></span>
                                    <strong>{{ $item->title }}</strong>
                                    <span style="opacity:.7;">({{ $item->category->name }})</span>
                                    @if ($item->description)
                                        <span style="display:block;opacity:.85;white-space:pre-line;">{{ $item->description }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
