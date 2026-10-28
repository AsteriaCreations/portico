<?php

namespace App\Models;

use Database\Factories\IssueReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "Report a problem" snapshot, with personal details left out. Written
 * only by App\Services\IssueReportService; see IssueReportPolicy.
 */
#[Fillable(['page', 'note', 'snapshot', 'reported_by', 'posted_url', 'posted_at', 'posted_by'])]
class IssueReport extends Model
{
    /** @use HasFactory<IssueReportFactory> */
    use HasFactory;

    // Append-only audit trail: rows are never edited.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
