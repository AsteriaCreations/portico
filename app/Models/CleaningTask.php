<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\CleaningTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'sort_order', 'active'])]
class CleaningTask extends Model
{
    /** @use HasFactory<CleaningTaskFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function completions(): HasMany
    {
        return $this->hasMany(CleaningTaskCompletion::class);
    }

    public function isCompletedForWeek(Carbon $weekStart): bool
    {
        return $this->completions()->whereDate('for_week_start', $weekStart)->exists();
    }
}
