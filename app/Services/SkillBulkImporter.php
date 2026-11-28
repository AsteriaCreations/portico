<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Concerns\ParsesSpreadsheetValues;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Bulk-adds skills to the Skills catalog from an uploaded spreadsheet,
 * with SkillForm's own rules: a name up to 60 characters, a description
 * up to 255, a whole-number sort order. "Never guess, skip and log", like
 * the other importers.
 *
 * Columns (row 1 is the header): name, optional description, optional
 * sort order (blank is 0), optional active (yes/no, blank is yes). A skill
 * already in the catalog (same name, any case) is skipped, so re-uploading
 * a file never adds anything twice. Tagging members with skills is a
 * separate, Admin+ upload (MemberSkillBulkImporter).
 */
class SkillBulkImporter
{
    use ParsesSpreadsheetValues;

    /**
     * @return array{created: int, log: string[]}
     */
    public function import(string $filePath): array
    {
        $result = DB::transaction(fn (): array => $this->process($filePath, true));

        return ['created' => $result['created'], 'log' => $result['log']];
    }

    /**
     * Every non-blank row with its problem, or null when it would be added.
     *
     * @return list<array{row: int, name: string, description: string, sort_order: string, active: string, problem: ?string}>
     */
    public function check(string $filePath): array
    {
        return $this->process($filePath, false)['rows'];
    }

    /**
     * @return array{created: int, log: string[], rows: list<array{row: int, name: string, description: string, sort_order: string, active: string, problem: ?string}>}
     */
    private function process(string $filePath, bool $write): array
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();
        $existing = Skill::pluck('name')->mapWithKeys(fn (string $name): array => [mb_strtolower($name) => true])->all();

        $created = 0;
        $log = [];
        $rows = [];
        $seen = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $name = trim((string) $sheet->getCell("A{$row}")->getValue());
            $description = trim((string) $sheet->getCell("B{$row}")->getValue());
            $sortRaw = trim((string) $sheet->getCell("C{$row}")->getValue());
            $activeRaw = trim((string) $sheet->getCell("D{$row}")->getValue());

            if ($name === '' && $description === '' && $sortRaw === '' && $activeRaw === '') {
                continue;
            }

            $key = mb_strtolower($name);
            $active = true;

            try {
                $active = $this->parseBoolean($activeRaw, 'active', true);
                $activeProblem = null;
            } catch (RuntimeException $exception) {
                $activeProblem = $exception->getMessage();
            }

            $problem = match (true) {
                $name === '' => 'blank name',
                mb_strlen($name) > 60 => 'name longer than 60 characters',
                mb_strlen($description) > 255 => 'description longer than 255 characters',
                $sortRaw !== '' && ! preg_match('/^-?\d+$/', $sortRaw) => "sort order '{$sortRaw}' must be a whole number",
                $activeProblem !== null => $activeProblem,
                isset($seen[$key]) => "'{$name}' is already in this file at row {$seen[$key]}",
                isset($existing[$key]) => "'{$name}' is already in the Skills catalog",
                default => null,
            };

            if ($problem === null) {
                $seen[$key] = $row;
            }

            $rows[] = [
                'row' => $row,
                'name' => $name,
                'description' => $description,
                'sort_order' => $sortRaw,
                'active' => $activeRaw,
                'problem' => $problem,
            ];

            if ($problem !== null) {
                $log[] = "row {$row}: {$problem}, skipped";

                continue;
            }

            if ($write) {
                Skill::create([
                    'name' => $name,
                    'description' => $description === '' ? null : $description,
                    'sort_order' => $sortRaw === '' ? 0 : (int) $sortRaw,
                    'active' => $active,
                ]);

                $created++;
            }
        }

        return ['created' => $created, 'log' => $log, 'rows' => $rows];
    }
}
