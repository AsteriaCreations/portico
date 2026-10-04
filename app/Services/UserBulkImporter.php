<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Member;
use App\Models\User;
use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bulk-creates staff accounts from an uploaded spreadsheet -- same "never
 * guess, skip and log" philosophy as the other bulk importers. Each row
 * follows the Users form's own rules: a name, a unique email, a role the
 * uploader may grant (User::canGrantRole(), as CreateUser enforces), and an
 * optional linked member (number or username, like PrepayListImporter) not
 * already linked to another account.
 *
 * Nobody types passwords into a spreadsheet: every new account gets a random
 * TemporaryPassword and must choose its own at first sign-in, the same as
 * the Reset password action. The passwords are returned once (ListUsers
 * streams them to the uploader as a CSV), and never stored or logged in plain text.
 *
 * An email that already has an account is skipped, so re-uploading the same
 * file never creates anyone twice.
 */
class UserBulkImporter
{
    /**
     * @return array{created: list<array{name: string, email: string, password: string}>, log: string[]}
     */
    public function import(string $filePath, User $createdBy): array
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();

        $created = [];
        $log = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $name = trim((string) $sheet->getCell("A{$row}")->getValue());
            $email = trim((string) $sheet->getCell("B{$row}")->getValue());
            $roleRaw = trim((string) $sheet->getCell("C{$row}")->getValue());
            $memberIdentifier = trim((string) $sheet->getCell("D{$row}")->getValue());

            if ($name === '' && $email === '' && $roleRaw === '' && $memberIdentifier === '') {
                continue;
            }

            if ($name === '' || mb_strlen($name) > 255) {
                $log[] = "row {$row}: name must be 1-255 characters, skipped";

                continue;
            }

            if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
                $log[] = "row {$row}: '{$email}' isn't a valid email address, skipped";

                continue;
            }

            if (User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists()) {
                $log[] = "row {$row}: {$email} already has an account, skipped";

                continue;
            }

            $role = $this->resolveRole($roleRaw);

            if ($role === null) {
                $log[] = "row {$row}: unrecognized role '{$roleRaw}', skipped";

                continue;
            }

            if (! User::canGrantRole($createdBy->role, $role)) {
                $log[] = "row {$row}: you can't give the {$role->displayLabel()} role, skipped";

                continue;
            }

            $member = null;

            if ($memberIdentifier !== '') {
                $member = ctype_digit($memberIdentifier)
                    ? Member::where('member_number', (int) $memberIdentifier)->first()
                    : Member::where('username', $memberIdentifier)->first();

                if (! $member) {
                    $log[] = "row {$row}: no member found for identifier '{$memberIdentifier}', skipped";

                    continue;
                }

                if (User::where('member_id', $member->id)->exists()) {
                    $log[] = "row {$row}: {$member->username} is already linked to another account, skipped";

                    continue;
                }
            }

            $password = TemporaryPassword::generate();

            User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => $role,
                'active' => true,
                'member_id' => $member?->id,
                'must_change_password' => true,
            ]);

            $created[] = ['name' => $name, 'email' => $email, 'password' => $password];
        }

        return ['created' => $created, 'log' => $log];
    }

    /**
     * Accepts the role's stored value ("door"), its generic label ("Event
     * Lead") or this club's own alias for it (Role Labels), case-insensitive.
     */
    private function resolveRole(string $value): ?Role
    {
        $needle = mb_strtolower($value);

        if ($needle === '') {
            return null;
        }

        foreach (Role::cases() as $role) {
            if (in_array($needle, [$role->value, mb_strtolower($role->getLabel()), mb_strtolower($role->displayLabel())], true)) {
                return $role;
            }
        }

        return null;
    }
}
