<?php

namespace App\Services;

use App\Models\RoleTask;
use App\Models\ShiftRole;
use App\Models\TaskArea;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bulk-adds standard tasks to shift roles' task lists from an uploaded
 * spreadsheet, with RoleTaskForm's own rules: an active shift role, a task
 * name up to 100 characters, details up to 255, a whole-number sort order.
 * "Never guess, skip and log", like the other importers.
 *
 * Columns (row 1 is the header): shift role name, task, optional area,
 * optional details, optional sort order. Names match case-insensitively.
 * An area not yet on file is created (active, sort order 0), so one file
 * can set up a whole list; check() reports each area it would create, so a
 * mistyped area shows up before the real upload. A task already on that
 * role's list (same name, any status) is skipped, so re-uploading a file
 * never adds anything twice.
 */
class RoleTaskBulkImporter
{
    /**
     * @return array{created: int, areas_created: int, log: string[]}
     */
    public function import(string $filePath): array
    {
        $result = DB::transaction(fn (): array => $this->process($filePath, true));

        return ['created' => $result['created'], 'areas_created' => $result['areas_created'], 'log' => $result['log']];
    }

    /**
     * Every non-blank row with what an upload would do with it: its problem,
     * or null when it would be added (with a note when it creates an area).
     *
     * @return list<array{row: int, role: string, task: string, area: string, details: string, sort_order: string, problem: ?string, note: ?string}>
     */
    public function check(string $filePath): array
    {
        return $this->process($filePath, false)['rows'];
    }

    /**
     * @return array{created: int, areas_created: int, log: string[], rows: list<array{row: int, role: string, task: string, area: string, details: string, sort_order: string, problem: ?string, note: ?string}>}
     */
    private function process(string $filePath, bool $write): array
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();

        $roles = ShiftRole::where('active', true)->get()->keyBy(fn (ShiftRole $role): string => mb_strtolower($role->name));
        $areas = TaskArea::all()->keyBy(fn (TaskArea $area): string => mb_strtolower($area->name));

        $created = 0;
        $areasCreated = 0;
        $newAreas = [];
        $log = [];
        $rows = [];
        $seen = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $roleName = trim((string) $sheet->getCell("A{$row}")->getValue());
            $task = trim((string) $sheet->getCell("B{$row}")->getValue());
            $areaName = trim((string) $sheet->getCell("C{$row}")->getValue());
            $details = trim((string) $sheet->getCell("D{$row}")->getValue());
            $sortRaw = trim((string) $sheet->getCell("E{$row}")->getValue());

            if ($roleName === '' && $task === '' && $areaName === '' && $details === '' && $sortRaw === '') {
                continue;
            }

            $role = $roles->get(mb_strtolower($roleName));
            $key = $role ? $role->id.'|'.mb_strtolower($task) : null;

            $problem = match (true) {
                $roleName === '' => 'blank shift role',
                $role === null => "no active shift role named '{$roleName}'",
                $task === '' => 'blank task',
                mb_strlen($task) > 100 => 'task longer than 100 characters',
                mb_strlen($areaName) > 60 => 'area longer than 60 characters',
                mb_strlen($details) > 255 => 'details longer than 255 characters',
                $sortRaw !== '' && ! preg_match('/^-?\d+$/', $sortRaw) => "sort order '{$sortRaw}' must be a whole number",
                isset($seen[$key]) => "'{$task}' for {$role->name} is already in this file at row {$seen[$key]}",
                RoleTask::where('shift_role_id', $role->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($task)])->exists() => "'{$task}' is already on {$role->name}'s list",
                default => null,
            };

            $note = null;
            $areaKey = mb_strtolower($areaName);

            if ($problem === null) {
                $seen[$key] = $row;

                if ($areaName !== '' && ! $areas->has($areaKey) && ! isset($newAreas[$areaKey])) {
                    $note = "creates area '{$areaName}'";
                    $newAreas[$areaKey] = true;
                }
            }

            $rows[] = [
                'row' => $row,
                'role' => $roleName,
                'task' => $task,
                'area' => $areaName,
                'details' => $details,
                'sort_order' => $sortRaw,
                'problem' => $problem,
                'note' => $note,
            ];

            if ($problem !== null) {
                $log[] = "row {$row}: {$problem}, skipped";

                continue;
            }

            if (! $write) {
                continue;
            }

            if ($areaName !== '' && ! $areas->has($areaKey)) {
                $areas->put($areaKey, TaskArea::create(['name' => $areaName, 'sort_order' => 0, 'active' => true]));
                $areasCreated++;
                $log[] = "row {$row}: created area '{$areaName}'";
            }

            RoleTask::create([
                'shift_role_id' => $role->id,
                'task_area_id' => $areaName === '' ? null : $areas->get($areaKey)->id,
                'name' => $task,
                'description' => $details === '' ? null : $details,
                'sort_order' => $sortRaw === '' ? 0 : (int) $sortRaw,
                'active' => true,
            ]);

            $created++;
        }

        return ['created' => $created, 'areas_created' => $areasCreated, 'log' => $log, 'rows' => $rows];
    }
}
