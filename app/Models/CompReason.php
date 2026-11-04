<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\CompReasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'grants_voucher_amount', 'voucher_only_if_already_covered', 'door_allowed', 'sort_order', 'active'])]
class CompReason extends Model
{
    /** @use HasFactory<CompReasonFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'grants_voucher_amount' => 'decimal:2',
            'sort_order' => 'integer',
            'active' => 'boolean',
            'door_allowed' => 'boolean',
            'voucher_only_if_already_covered' => 'boolean',
        ];
    }

    /**
     * The active reasons this user may comp an entry with at the desk:
     * every one for Manager+, only the door-allowed ones for Door, none
     * below Door. The grant-event-comp gate is the authority; this only
     * builds the picker's options and decides whether to offer it at all.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function grantableBy(Builder $query, User $user): void
    {
        $query->where('active', true);

        if ($user->role->atLeast(Role::Manager)) {
            return;
        }

        if ($user->role->atLeast(Role::Door)) {
            $query->where('door_allowed', true);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
