<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Skill;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bulk-tags members with skills from an uploaded spreadsheet: the upload
 * equivalent of MemberForm's Skills field, and gated the same way
 * (assign-member-skills, Admin+). It only ever adds a tag, never removes
 * one. "Never guess, skip and log", like the other importers.
 *
 * Columns (row 1 is the header): member number or username, skill name
 * (case-insensitive). The skill must be active, so a retired skill isn't
 * handed out by accident. A member who already has the skill is skipped,
 * so re-uploading a file never changes anything twice.
 */
class MemberSkillBulkImporter
{
    /**
     * @return array{created: int, log: string[]}
     */
    public function import(string $filePath): array
    {
        $result = DB::transaction(fn (): array => $this->process($filePath, true));

        return ['created' => $result['created'], 'log' => $result['log']];
    }

    /**
     * Every non-blank row with the member it matched (if any) and its
     * problem, or null when the tag would be added.
     *
     * @return list<array{row: int, identifier: string, skill: string, member: ?string, problem: ?string}>
     */
    public function check(string $filePath): array
    {
        return $this->process($filePath, false)['rows'];
    }

    /**
     * @return array{created: int, log: string[], rows: list<array{row: int, identifier: string, skill: string, member: ?string, problem: ?string}>}
     */
    private function process(string $filePath, bool $write): array
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();
        $skills = Skill::where('active', true)->get()->keyBy(fn (Skill $skill): string => mb_strtolower($skill->name));

        $created = 0;
        $log = [];
        $rows = [];
        $seen = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $identifier = trim((string) $sheet->getCell("A{$row}")->getValue());
            $skillName = trim((string) $sheet->getCell("B{$row}")->getValue());

            if ($identifier === '' && $skillName === '') {
                continue;
            }

            $member = $identifier === '' ? null : (ctype_digit($identifier)
                ? Member::where('member_number', (int) $identifier)->first()
                : Member::where('username', $identifier)->first());
            $skill = $skills->get(mb_strtolower($skillName));
            $key = $member && $skill ? "{$member->id}|{$skill->id}" : null;

            $problem = match (true) {
                $identifier === '' => 'blank member identifier',
                $member === null => "no member found for identifier '{$identifier}'",
                $skillName === '' => 'blank skill',
                $skill === null => "no active skill named '{$skillName}'",
                isset($seen[$key]) => "same member and skill as row {$seen[$key]}",
                $member->skills()->whereKey($skill->id)->exists() => "{$member->username} already has {$skill->name}",
                default => null,
            };

            if ($problem === null) {
                $seen[$key] = $row;
            }

            $rows[] = [
                'row' => $row,
                'identifier' => $identifier,
                'skill' => $skillName,
                'member' => $member?->username,
                'problem' => $problem,
            ];

            if ($problem !== null) {
                $log[] = "row {$row}: {$problem}, skipped";

                continue;
            }

            if ($write) {
                $member->skills()->attach($skill->id);
                $created++;
            }
        }

        return ['created' => $created, 'log' => $log, 'rows' => $rows];
    }
}
