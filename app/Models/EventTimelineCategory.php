<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\EventTimelineCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who an event timeline item is for. A public category is for attendees
 * (the printed sheet and the display screen) and every signed-in user;
 * any other is seen by staff at or above min_role (no min_role: the
 * showrunner and Admin+ only). See EventTimelineService::visibleCategories().
 */
#[Fillable(['name', 'color', 'is_public', 'min_role', 'sort_order'])]
class EventTimelineCategory extends Model
{
    /** @use HasFactory<EventTimelineCategoryFactory> */
    use HasFactory;

    /**
     * The colours a category can take: distinct, and dark enough for white
     * text on them in either theme.
     *
     * @return array<string, string>
     */
    public static function colorOptions(): array
    {
        return [
            '#2563eb' => __('Blue'),
            '#d97706' => __('Amber'),
            '#059669' => __('Green'),
            '#7c3aed' => __('Purple'),
            '#db2777' => __('Pink'),
            '#dc2626' => __('Red'),
            '#0891b2' => __('Teal'),
            '#4b5563' => __('Grey'),
        ];
    }

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'min_role' => Role::class,
            'sort_order' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(EventTimelineItem::class);
    }
}
