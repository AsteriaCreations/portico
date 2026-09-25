<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; font-size: 14px; color: #111;">
    <p><strong>{{ $event->name }}</strong> — {{ $event->event_date->translatedFormat('M j, Y') }}</p>

    <table cellpadding="4" cellspacing="0">
        <tr>
            <td>{{ __('Checked in') }}</td>
            <td><strong>{{ $summary['checked_in'] }}</strong></td>
        </tr>
        <tr>
            <td>{{ __('Prepaid, never arrived') }}</td>
            <td><strong>{{ $summary['prepaid_no_show'] }}</strong></td>
        </tr>
        <tr>
            <td>{{ __('Revenue') }}</td>
            <td><strong>{{ \App\Models\MembershipSetting::formatMoney(\App\Support\Cents::toFloat($summary['revenue_cents'])) }}</strong></td>
        </tr>
    </table>
</body>
</html>
