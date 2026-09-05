<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Manager+ can view the history and record a new signing. Append-only, like
 * BanException / Voucher / MemberStatusChange -- a mistaken date is fixed by
 * recording a corrected signing, never by editing or deleting a row.
 */
class MemberPaperworkPolicy extends RoleGatedPolicy
{
    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
