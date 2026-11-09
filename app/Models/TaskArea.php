<?php

namespace App\Models;

use Database\Factories\TaskAreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a role task is done (Bathrooms, Main floor, Bar…), with notes on
 * how that area is handled, since some are cared for differently. Not the
 * private-rental rooms: an area has no rates.
 */
#[Fillable(['name', 'notes', 'sort_order', 'active'])]
class TaskArea extends Model
{
    /** @use HasFactory<TaskAreaFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(RoleTask::class);
    }
}
