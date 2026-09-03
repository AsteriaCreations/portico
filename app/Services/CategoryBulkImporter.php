<?php

namespace App\Services;

use App\Models\Category;
use App\Services\Concerns\ParsesSpreadsheetValues;
use Illuminate\Database\QueryException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Bulk-creates categories from an uploaded spreadsheet — same "never guess,
 * skip and log" philosophy as EventBulkImporter/MemberBulkImporter. Create-only:
 * a row whose name already exists (case-insensitively) is skipped, never used
 * to update the existing category.
 */
class CategoryBulkImporter
{
    use ParsesSpreadsheetValues;

    /**
     * @return array{created: int, log: string[]}
     */
    public function import(string $filePath): array
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();

        $created = 0;
        $log = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $name = trim((string) $sheet->getCell("A{$row}")->getValue());
            $description = trim((string) $sheet->getCell("B{$row}")->getValue());
            $isCompedRaw = trim((string) $sheet->getCell("C{$row}")->getValue());
            $sortOrderRaw = trim((string) $sheet->getCell("D{$row}")->getValue());
            $activeRaw = trim((string) $sheet->getCell("E{$row}")->getValue());

            if ($name === '') {
                continue;
            }

            if (Category::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                $log[] = "row {$row}: a category named '{$name}' already exists, skipped";

                continue;
            }

            if ($sortOrderRaw !== '' && ! ctype_digit($sortOrderRaw)) {
                $log[] = "row {$row}: non-numeric sort_order '{$sortOrderRaw}', skipped";

                continue;
            }

            try {
                $isComped = $this->parseBoolean($isCompedRaw, 'is_comped', default: false);
                $active = $this->parseBoolean($activeRaw, 'active', default: true);
            } catch (RuntimeException $exception) {
                $log[] = "row {$row}: {$exception->getMessage()}, skipped";

                continue;
            }

            try {
                Category::create([
                    'name' => $name,
                    'description' => $description !== '' ? $description : null,
                    'is_comped' => $isComped,
                    'sort_order' => $sortOrderRaw !== '' ? (int) $sortOrderRaw : 0,
                    'active' => $active,
                ]);
            } catch (QueryException) {
                $log[] = "row {$row}: category name already taken, skipped";

                continue;
            }

            $created++;
        }

        return ['created' => $created, 'log' => $log];
    }
}
