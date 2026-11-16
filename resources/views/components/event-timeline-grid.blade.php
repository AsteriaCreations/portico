@props(['grid', 'editable' => false, 'caption'])

{{--
    An event's run of show as a day view, from the event's start (top) to its
    end (bottom): an hour line per hour, each item a block in its category's
    colour, overlapping items side by side. When editable, clicking empty
    space adds an item there (mouse only: the Add item button does the same
    from the keyboard) and clicking an item changes it. Inline styles only,
    so it needs no theme rebuild and prints as is. Data from
    App\Services\EventTimelineService::layout().
--}}
@php
    $rowRem = 1.4;
    $height = max(8, $grid['slotCount'] * $rowRem);
    $gutter = '4.75rem';
    $muted = 'opacity:.65;';
@endphp

<figure class="event-timeline-grid" style="margin:0;font-size:.875rem;">
    <figcaption style="font-weight:600;padding-bottom:.5rem;">{{ $caption }}</figcaption>

    <div style="position:relative;height:{{ $height }}rem;border:1px solid rgba(128,128,128,.35);border-radius:.5rem;overflow:hidden;">
        @foreach ($grid['hours'] as $hour)
            <div aria-hidden="true" style="position:absolute;left:0;right:0;top:{{ $hour['top'] }}%;border-top:1px solid rgba(128,128,128,.3);">
                <span style="position:absolute;left:.4rem;top:.1rem;font-size:.75rem;{{ $muted }}">{{ $hour['label'] }}</span>
            </div>
        @endforeach

        <div style="position:absolute;top:0;bottom:0;left:{{ $gutter }};right:0;border-left:1px solid rgba(128,128,128,.3);">
            @if ($editable)
                @foreach ($grid['slots'] as $slot)
                    <button
                        type="button"
                        tabindex="-1"
                        aria-hidden="true"
                        title="{{ __('Add at :time', ['time' => $slot['label']]) }}"
                        wire:click="mountAction('addItem', @js(['at' => $slot['at']]))"
                        class="event-timeline-slot"
                        style="position:absolute;left:0;right:0;top:{{ $slot['top'] }}%;height:{{ $rowRem }}rem;background:transparent;border:0;cursor:copy;"
                    ></button>
                @endforeach
            @endif

            @foreach ($grid['blocks'] as $block)
                @php
                    $item = $block['item'];
                    $width = 100 / $block['lanes'];
                    $style = 'position:absolute;top:'.$block['top'].'%;height:'.$block['height'].'%;min-height:1.3rem;'
                        .'left:calc('.($block['lane'] * $width).'% + 2px);width:calc('.$width.'% - 4px);'
                        .'background:'.$item->category->color.';color:#fff;border:1px solid rgba(0,0,0,.2);border-radius:.375rem;'
                        .'padding:.15rem .4rem;overflow:hidden;text-align:left;line-height:1.25;box-sizing:border-box;';
                    $label = __(':title, :time, :category', ['title' => $item->title, 'time' => $block['time'], 'category' => $item->category->name]);
                @endphp
                @if ($editable)
                    <button type="button" wire:click="mountAction('editItem', @js(['item' => $item->id]))" aria-label="{{ __('Change :item', ['item' => $label]) }}" style="{{ $style }}cursor:pointer;">
                        <strong style="display:block;font-size:.8rem;">{{ $item->title }}</strong>
                        <span style="font-size:.72rem;opacity:.9;">{{ $block['time'] }}</span>
                    </button>
                @else
                    <div role="note" aria-label="{{ $label }}" style="{{ $style }}">
                        <strong style="display:block;font-size:.8rem;">{{ $item->title }}</strong>
                        <span style="font-size:.72rem;opacity:.9;">{{ $block['time'] }}</span>
                    </div>
                @endif
            @endforeach
        </div>

        {{-- The "now" line: placed by the server as the page loads, then
        moved on every 30 s in the browser from the event's bounds, so the
        page needn't poll (and an open dialog is left alone). Hidden outside
        the event. Clicks pass through it. --}}
        <div
            class="event-timeline-now"
            role="img"
            x-data="{
                startsAt: {{ $grid['startsAtMs'] }},
                endsAt: {{ $grid['endsAtMs'] }},
                top: @js($grid['now']['top'] ?? null),
                label: @js($grid['now']['label'] ?? ''),
                tick() {
                    const now = Date.now();
                    this.top = now >= this.startsAt && now <= this.endsAt ? (now - this.startsAt) / (this.endsAt - this.startsAt) * 100 : null;
                    this.label = new Date(now).toLocaleTimeString(document.documentElement.lang || undefined, { hour: 'numeric', minute: '2-digit' });
                },
            }"
            x-init="tick(); setInterval(() => tick(), 30000)"
            x-show="top !== null"
            {{-- Object form: a string would replace the whole style attribute. --}}
            :style="{ top: top === null ? null : top + '%' }"
            :aria-label="@js(__('Now')) + ', ' + label"
            aria-label="{{ __('Now') }}, {{ $grid['now']['label'] ?? '' }}"
            style="position:absolute;left:0;right:0;height:0;border-top:2px solid #dc2626;z-index:2;pointer-events:none;{{ $grid['now'] ? 'top:'.$grid['now']['top'].'%;' : 'display:none;' }}"
        >
            <span x-text="label" style="position:absolute;left:.25rem;top:-.7rem;padding:0 .3rem;border-radius:.25rem;background:#dc2626;color:#fff;font-size:.7rem;font-weight:600;line-height:1.3rem;">{{ $grid['now']['label'] ?? '' }}</span>
        </div>
    </div>

    <style>
        .event-timeline-slot:hover { background: rgba(128,128,128,.12) !important; }
        @media print {
            .fi-sidebar, .fi-topbar, .fi-header-actions, details.fi-section, .event-timeline-slot, .event-timeline-now { display: none !important; }
            .fi-main, .fi-page { padding: 0 !important; margin: 0 !important; max-width: none !important; }
            .event-timeline-grid div[role="note"], .event-timeline-grid button { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</figure>
