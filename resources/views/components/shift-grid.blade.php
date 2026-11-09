@props(['grid', 'caption'])

{{--
    The week as the club's paper schedule: Open/Close columns per event, a row
    per role; setup, resets and other shifts on the right; one-shift nights
    underneath; then who's Off (here, not working) and Out (not here) each
    night. Inline styles only, so it needs no theme rebuild and prints as is.
    Data from App\Services\ShiftGridService::build().
--}}
@php
    $cell = 'border:1px solid rgba(128,128,128,.35);padding:.4rem .55rem;vertical-align:top;text-align:left;';
    $head = $cell.'font-weight:600;background:rgba(128,128,128,.08);';
    $muted = 'opacity:.65;';
    $open = 'font-size:.8em;font-weight:600;color:#b45309;';
    $names = fn (array $people): string => $people === [] ? '' : implode(', ', $people);
    $srOnly = 'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;';
@endphp

<div class="shift-grid" style="display:flex;flex-wrap:wrap;gap:1.25rem;align-items:flex-start;font-size:.9rem;">
    <style>
        @media print {
            .fi-sidebar, .fi-topbar, .fi-header-actions, .fi-page-header-main-ctn > .fi-ac, details.fi-section { display: none !important; }
            .fi-main, .fi-page { padding: 0 !important; margin: 0 !important; max-width: none !important; }
            .shift-grid { font-size: 10pt !important; color: #000 !important; }
            .shift-grid * { color: #000 !important; }
        }
    </style>

    <div style="flex:1 1 32rem;min-width:0;overflow-x:auto;">
        @if ($grid['columns'] === [])
            <p style="{{ $muted }}">{{ __('No Open or Close shifts this week.') }}</p>
        @else
            <table style="border-collapse:collapse;width:100%;">
                <caption style="text-align:left;font-weight:600;padding-bottom:.4rem;">{{ $caption }}</caption>
                <thead>
                    <tr>
                        <th scope="col" style="{{ $head }}"><span style="{{ $srOnly }}">{{ __('Role') }}</span></th>
                        @foreach ($grid['columns'] as $column)
                            <th scope="col" style="{{ $head }}">
                                {{ __(':day :kind', ['day' => $column['day'], 'kind' => $column['kind']]) }}
                                <div style="font-weight:400;font-size:.8em;{{ $muted }}">{{ $column['event'] }}</div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grid['rows'] as $row)
                        <tr>
                            <th scope="row" style="{{ $head }}">{{ $row['role'] }}</th>
                            @foreach ($grid['columns'] as $column)
                                @php
                                    $people = $row['cells'][$column['key']] ?? null;
                                @endphp
                                <td style="{{ $cell }}">
                                    @if ($people === null)
                                        <span style="{{ $muted }}" aria-label="{{ __('Not needed') }}">—</span>
                                    @else
                                        {{ $names($people['names']) }}
                                        @if ($people['open'] > 0)
                                            <div style="{{ $open }}">{{ trans_choice(':count open|:count open', $people['open']) }}</div>
                                        @endif
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($grid['weekday'] !== [])
            <table style="border-collapse:collapse;width:100%;margin-top:1rem;">
                <caption style="text-align:left;font-weight:600;padding-bottom:.4rem;">{{ __('Weekday shifts') }}</caption>
                <thead>
                    <tr>
                        @foreach ($grid['weekday'] as $night)
                            <th scope="col" style="{{ $head }}">
                                {{ $night['day'] }}
                                <div style="font-weight:400;font-size:.8em;{{ $muted }}">{{ $night['event'] }}</div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        @foreach ($grid['weekday'] as $night)
                            <td style="{{ $cell }}">
                                @foreach ($night['shifts'] as $shift)
                                    <div><strong>{{ $shift['shift'] }}:</strong> {{ $names($shift['names']) ?: '—' }}
                                        @if ($shift['open'] > 0)
                                            <span style="{{ $open }}">{{ trans_choice(':count open|:count open', $shift['open']) }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        @endif

        @if ($grid['nights'] !== [])
            <table style="border-collapse:collapse;width:100%;margin-top:1rem;">
                <caption style="text-align:left;font-weight:600;padding-bottom:.4rem;">{{ __('Not on shift') }}</caption>
                <thead>
                    <tr>
                        <th scope="col" style="{{ $head }}"><span style="{{ $srOnly }}">{{ __('Night') }}</span></th>
                        <th scope="col" style="{{ $head }}">{{ __('Off (will be there)') }}</th>
                        <th scope="col" style="{{ $head }}">{{ __('Out (won\'t be there)') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($grid['nights'] as $night)
                        <tr>
                            <th scope="row" style="{{ $head }}">{{ $night['day'] }}</th>
                            <td style="{{ $cell }}">{{ $names($night['off']) ?: '—' }}</td>
                            <td style="{{ $cell }}">{{ $names($night['out']) ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div style="flex:0 1 16rem;min-width:13rem;">
        <table style="border-collapse:collapse;width:100%;">
            <caption style="text-align:left;font-weight:600;padding-bottom:.4rem;">{{ __('Setup, resets and other shifts') }}</caption>
            <tbody>
                @forelse ($grid['side'] as $shift)
                    <tr>
                        <td style="{{ $cell }}">
                            <strong>{{ $shift['shift'] }}</strong>
                            <div style="font-size:.85em;{{ $muted }}">{{ $shift['when'] }}</div>
                            <div>{{ $names($shift['names']) ?: '—' }}</div>
                            @if ($shift['open'] > 0)
                                <div style="{{ $open }}">{{ trans_choice(':count open|:count open', $shift['open']) }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td style="{{ $cell }}{{ $muted }}">{{ __('None this week.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
