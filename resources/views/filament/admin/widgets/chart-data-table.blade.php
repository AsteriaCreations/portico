{{-- A chart's numbers as a table. AnalyticsChartWidget renders it inside
     Filament's screen-reader-only block under the canvas. --}}
<table>
    <caption>{{ $caption }}</caption>
    <thead>
        <tr>
            <th scope="col">{{ __('Period') }}</th>
            @foreach ($data['datasets'] as $dataset)
                <th scope="col">{{ $dataset['label'] }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($data['labels'] as $index => $label)
            <tr>
                <th scope="row">{{ $label }}</th>
                @foreach ($data['datasets'] as $dataset)
                    <td>{{ $format($dataset['data'][$index]) }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
