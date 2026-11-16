<x-filament-widgets::widget>
    <div wire:poll.60s>
        @php($timelines = $this->getTimelines())
        <x-filament::section :heading="__('Tonight\'s timeline')">
            {{-- role="status" so what's on now is announced when the poll moves it on. --}}
            <div role="status" style="display:grid;gap:1rem;">
                @forelse ($timelines as $timeline)
                    <div>
                        @if (count($timelines) > 1)
                            <p style="font-weight:600;margin:0 0 .35rem;">{{ $timeline['event']->name ?? __('Untitled event') }}</p>
                        @endif
                        <dl style="display:grid;grid-template-columns:auto 1fr;gap:.25rem .75rem;margin:0;font-size:.875rem;">
                            <dt style="font-weight:600;">{{ __('Now') }}</dt>
                            <dd style="margin:0;">
                                @forelse ($timeline['now'] as $item)
                                    <span style="display:block;">
                                        <span aria-hidden="true" style="display:inline-block;width:.6rem;height:.6rem;border-radius:.15rem;background:{{ $item->category->color }};"></span>
                                        <strong>{{ $item->title }}</strong>
                                        <span style="opacity:.7;">{{ $item->ends_at ? __('until :time', ['time' => $item->ends_at->isoFormat('LT')]) : $item->starts_at->isoFormat('LT') }}</span>
                                    </span>
                                @empty
                                    <span style="opacity:.7;">{{ __('Nothing scheduled right now') }}</span>
                                @endforelse
                            </dd>
                            <dt style="font-weight:600;">{{ __('Up next') }}</dt>
                            <dd style="margin:0;">
                                @forelse ($timeline['next'] as $item)
                                    <span style="display:block;">
                                        <span style="font-variant-numeric:tabular-nums;">{{ $item->starts_at->isoFormat('LT') }}</span>
                                        <span aria-hidden="true" style="display:inline-block;width:.6rem;height:.6rem;border-radius:.15rem;background:{{ $item->category->color }};"></span>
                                        {{ $item->title }}
                                    </span>
                                @empty
                                    <span style="opacity:.7;">{{ __('Nothing else tonight') }}</span>
                                @endforelse
                            </dd>
                        </dl>
                        <a href="{{ $timeline['url'] }}" style="font-size:.8rem;text-decoration:underline;">{{ __('Whole timeline') }}</a>
                    </div>
                @empty
                    <p style="opacity:.7;margin:0;">{{ __('Nothing left on tonight\'s timeline.') }}</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
