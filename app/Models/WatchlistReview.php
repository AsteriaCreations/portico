<?php

namespace App\Models;

use App\Enums\WatchlistReviewDecision;
use Database\Factories\WatchlistReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Owner decision on a watchlist entry. Written only by
 * Member::resolveWatchlistReview(); see WatchlistReviewPolicy.
 */
#[Fillable(['member_id', 'decision', 'previous_review_on', 'new_review_on', 'probation_started', 'notes', 'decided_by'])]
class WatchlistReview extends Model
{
    /** @use HasFactory<WatchlistReviewFactory> */
    use HasFactory;

    // Append-only audit trail: rows are never edited.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'decision' => WatchlistReviewDecision::class,
            'previous_review_on' => 'date',
            'new_review_on' => 'date',
            'probation_started' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
