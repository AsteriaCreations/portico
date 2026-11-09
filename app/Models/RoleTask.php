<?php

namespace App\Models;

use App\Enums\RoleTaskStatus;
use Database\Factories\RoleTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One task on a shift role's list (Desk: count the float; Cleaning: mop
 * the bathrooms), optionally in an area. A scheduled role's tasks are
 * ticked once per shift, an unscheduled role's (Cleaning) once per
 * schedule week. Staff with the role can propose one (status proposed);
 * a Manager+ makes it standard or declines it.
 */
#[Fillable(['shift_role_id', 'task_area_id', 'name', 'description', 'status', 'proposed_by', 'decided_by', 'decided_at', 'decline_reason', 'sort_order', 'active'])]
class RoleTask extends Model
{
    /** @use HasFactory<RoleTaskFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => RoleTaskStatus::class,
            'decided_at' => 'datetime',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function shiftRole(): BelongsTo
    {
        return $this->belongsTo(ShiftRole::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(TaskArea::class, 'task_area_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * The tasks staff work from: standard and active.
     *
     * @param  Builder<RoleTask>  $query
     */
    public function scopeStandard(Builder $query): void
    {
        $query->where('status', RoleTaskStatus::Standard)->where('active', true);
    }
}
