<?php

namespace App\Models;

use Database\Factories\PaperworkTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A signed form / waiver a club tracks per member -- Standard Paperwork,
 * an annually-renewed Pool Waiver (gates the Pool add-on), or anything a
 * club adds. Editable settings data, same shape as add_ons / comp_reasons.
 * Per-member signed dates live in member_paperwork;
 * Member::hasValidPaperwork() is the read side.
 */
#[Fillable(['name', 'description', 'required', 'renewal_months', 'gates_add_on_id', 'sort_order', 'active'])]
class PaperworkType extends Model
{
    /** @use HasFactory<PaperworkTypeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'renewal_months' => 'integer',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class, 'gates_add_on_id');
    }

    public function memberPaperwork(): HasMany
    {
        return $this->hasMany(MemberPaperwork::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
