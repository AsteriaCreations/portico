<?php

namespace App\Models;

use Database\Factories\AttendanceAddOnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['attendance_id', 'add_on_id', 'name', 'price', 'is_overnight'])]
class AttendanceAddOn extends Model
{
    /** @use HasFactory<AttendanceAddOnFactory> */
    use HasFactory;

    // Append-only snapshot: rows are never edited after creation.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_overnight' => 'boolean',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
    }
}
