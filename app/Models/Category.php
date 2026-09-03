<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'is_comped', 'sort_order', 'active'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    // Names business logic string-matches directly (Member::isProspective(),
    // MemberObserver's Guest check, CheckIn.php's Irregular/Guest lookups) —
    // the settings panel blocks renaming/deleting these specific rows since
    // doing so would silently break that code, not because the rest of the
    // table isn't manager-editable.
    public const array PROTECTED_NAMES = ['Prospective', 'Guest', 'Irregular'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_comped' => 'boolean',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }
}
