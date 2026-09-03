<?php

namespace App\Models;

use App\Enums\EntryCoverageSource;
use Database\Factories\InstructorPayRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['event_type_id', 'entry_covered_by', 'rate'])]
class InstructorPayRate extends Model
{
    /** @use HasFactory<InstructorPayRateFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'entry_covered_by' => EntryCoverageSource::class,
            'rate' => 'decimal:2',
        ];
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }
}
