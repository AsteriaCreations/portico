<?php

namespace App\Models;

use Database\Factories\ShiftRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A job staff work on a shift (Door, DM, Bar, Setup…): the club's own list,
 * separate from the login roles that decide what someone can do in the app.
 * users() is who can work it, so availability and assignment only offer
 * those people.
 */
#[Fillable(['name', 'description', 'sort_order', 'active'])]
class ShiftRole extends Model
{
    /** @use HasFactory<ShiftRoleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(ShiftTemplate::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(ShiftSlot::class);
    }
}
