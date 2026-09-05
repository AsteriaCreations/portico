<?php

namespace App\Models;

use Database\Factories\MemberPaperworkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record of a member signing a paperwork type on a given date. Append
 * only -- a renewed waiver is a new row, never an edit; the latest
 * signed_on per type is what counts (Member::hasValidPaperwork()).
 */
#[Fillable(['member_id', 'paperwork_type_id', 'signed_on', 'recorded_by'])]
class MemberPaperwork extends Model
{
    /** @use HasFactory<MemberPaperworkFactory> */
    use HasFactory;

    // Eloquent would pluralise to "member_paperworks"; the table is
    // "member_paperwork" (see its migration).
    protected $table = 'member_paperwork';

    // Append-only: rows are never edited after creation.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'signed_on' => 'date',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function paperworkType(): BelongsTo
    {
        return $this->belongsTo(PaperworkType::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
