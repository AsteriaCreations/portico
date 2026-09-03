<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; font-size: 14px; color: #111;">
    <p><strong>{{ $event->name }}</strong> — {{ $event->event_date->toFormattedDateString() }}</p>

    <table cellpadding="4" cellspacing="0">
        <tr>
            <td>Checked in</td>
            <td><strong>{{ $summary['checked_in'] }}</strong></td>
        </tr>
        <tr>
            <td>Prepaid, never arrived</td>
            <td><strong>{{ $summary['prepaid_no_show'] }}</strong></td>
        </tr>
        <tr>
            <td>Revenue</td>
            <td><strong>${{ number_format($summary['revenue'], 2) }}</strong></td>
        </tr>
    </table>
</body>
</html>
