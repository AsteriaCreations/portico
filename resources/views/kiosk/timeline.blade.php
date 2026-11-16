{{-- The display screen for tonight's public event timeline (route
kiosk.timeline): a TV or tablet by the door. Standalone, outside the panel,
no script: it reloads itself every $refreshSeconds. Public categories only,
from App\Services\EventTimelineService::publicDisplay(). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta http-equiv="refresh" content="{{ $refreshSeconds }}">
    <title>{{ __('Tonight\'s timeline') }}</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #111827; color: #f9fafb; font-family: system-ui, sans-serif; }
        main { max-width: 1200px; margin: 0 auto; padding: 24px 16px 40px; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 24px; }
        .mark { display: block; max-height: clamp(40px, 7vw, 72px); max-width: 50vw; width: auto; height: auto; opacity: .9; }
        .org { margin: 0; font-size: clamp(20px, 3vw, 28px); font-weight: 700; }
        .clock { font-size: clamp(28px, 5vw, 56px); font-weight: 700; font-variant-numeric: tabular-nums; }
        .events { display: grid; gap: 24px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 420px), 1fr)); }
        .event { padding: 20px; border-radius: 16px; background: #1f2937; }
        h1 { margin: 0 0 16px; font-size: clamp(24px, 3.5vw, 36px); }
        h2 { margin: 0 0 8px; font-size: clamp(14px, 1.8vw, 18px); text-transform: uppercase; letter-spacing: .08em; color: #9ca3af; }
        ul { list-style: none; margin: 0 0 20px; padding: 0; display: grid; gap: 10px; }
        .now li { padding: 14px 16px; border-radius: 12px; border-left: 8px solid var(--c); background: #111827; font-size: clamp(22px, 3.5vw, 36px); font-weight: 700; }
        .next li { display: flex; gap: 16px; align-items: baseline; padding-left: 12px; border-left: 6px solid var(--c); font-size: clamp(18px, 2.5vw, 26px); }
        .time { min-width: 5.5em; font-variant-numeric: tabular-nums; color: #d1d5db; }
        .until { display: block; margin-top: 4px; font-size: .55em; font-weight: 500; color: #d1d5db; }
        .detail { display: block; margin-top: 4px; font-size: .6em; font-weight: 400; color: #d1d5db; }
        .empty { margin: 0 0 20px; font-size: clamp(18px, 2.5vw, 24px); color: #9ca3af; }
        .closed { margin-top: 20vh; text-align: center; font-size: clamp(24px, 4vw, 40px); color: #d1d5db; }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <img class="mark" src="{{ $logoUrl }}" alt="{{ $orgName ?: '' }}">
        </div>
        <div class="clock" aria-label="{{ __('Time now') }}">{{ now()->isoFormat('LT') }}</div>
    </header>

    @if ($timelines === [])
        <p class="closed">{{ __('Nothing on the timeline right now.') }}</p>
    @else
        <div class="events">
            @foreach ($timelines as $timeline)
                <section class="event" aria-labelledby="event-{{ $timeline['event']->id }}">
                    <h1 id="event-{{ $timeline['event']->id }}">{{ $timeline['event']->name ?? __('Tonight') }}</h1>

                    <h2>{{ __('Now') }}</h2>
                    @if ($timeline['now']->isEmpty())
                        <p class="empty">{{ __('Nothing scheduled right now') }}</p>
                    @else
                        <ul class="now">
                            @foreach ($timeline['now'] as $item)
                                <li style="--c: {{ $item->category->color }};">
                                    {{ $item->title }}
                                    @if ($item->ends_at)
                                        <span class="until">{{ __('until :time', ['time' => $item->ends_at->isoFormat('LT')]) }}</span>
                                    @endif
                                    @if ($item->description)
                                        <span class="detail">{{ $item->description }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <h2>{{ __('Up next') }}</h2>
                    @if ($timeline['next']->isEmpty())
                        <p class="empty">{{ __('Nothing else tonight') }}</p>
                    @else
                        <ul class="next">
                            @foreach ($timeline['next'] as $item)
                                <li style="--c: {{ $item->category->color }};">
                                    <span class="time">{{ $item->starts_at->isoFormat('LT') }}</span>
                                    <span>{{ $item->title }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</main>
</body>
</html>
