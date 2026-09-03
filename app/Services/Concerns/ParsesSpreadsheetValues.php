<?php

namespace App\Services\Concerns;

use Carbon\Carbon;
use RuntimeException;
use Throwable;

/**
 * Shared boolean/date parsing for the bulk spreadsheet importers
 * (EventBulkImporter, MemberBulkImporter, CategoryBulkImporter,
 * CompReasonBulkImporter) — each throws a RuntimeException with a
 * field-specific message on an unparseable value, which every importer's
 * own row loop catches and turns into its "skip and log" row message. The
 * row-shape/domain-specific parsing (which columns exist, what a blank
 * value defaults to structurally) stays in each importer — this only
 * covers the parsing logic that was identical across all four.
 */
trait ParsesSpreadsheetValues
{
    private function parseBoolean(string $raw, string $field, bool $default): bool
    {
        if ($raw === '') {
            return $default;
        }

        $normalized = mb_strtolower($raw);

        if (in_array($normalized, ['y', 'yes', 'true', '1'], true)) {
            return true;
        }

        if (in_array($normalized, ['n', 'no', 'false', '0'], true)) {
            return false;
        }

        throw new RuntimeException("unrecognized {$field} value '{$raw}'");
    }

    private function parseDate(string $raw, string $field): ?string
    {
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (Throwable) {
            throw new RuntimeException("unparseable {$field} '{$raw}'");
        }
    }
}
