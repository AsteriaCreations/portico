<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A CSV built and sent in one request (there is no queue worker for
 * Filament's own exporter). Starts with a UTF-8 byte-order mark so Excel
 * reads accented names and symbols correctly.
 */
class CsvDownload
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, string|int|float|null>>  $rows
     */
    public static function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
