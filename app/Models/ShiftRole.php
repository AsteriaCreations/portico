<?php

namespace App\Models;

use Database\Factories\ShiftRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A job staff work on a shift (Door, DM, Bar, Setup…): the club's own list,
 * separate from the login roles that decide what someone can do in the app.
 * users() is who can work it, so availability and assignment only offer
 * those people -- unless open_to_all (Setup), when anyone on the roster can.
 */
#[Fillable(['name', 'description', 'open_to_all', 'counts_toward_requirement', 'sort_order', 'active'])]
class ShiftRole extends Model
{
    /** @use HasFactory<ShiftRoleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'open_to_all' => 'boolean',
            'counts_toward_requirement' => 'boolean',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Who can be offered and put on this role's shifts: its own list, or for
     * an open_to_all role everyone on the roster (an active login with at
     * least one active shift role).
     *
     * @return Collection<int, User>
     */
    public function eligibleUsers(): Collection
    {
        if (! $this->open_to_all) {
            return $this->users->where('active', true)->sortBy('name')->values();
        }

        return User::query()
            ->where('active', true)
            ->whereHas('shiftRoles', fn ($query) => $query->where('active', true))
            ->orderBy('name')
            ->get();
    }

    public function slots(): HasMany
    {
        return $this->hasMany(ShiftSlot::class);
    }
}
