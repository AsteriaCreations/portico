<?php

namespace App\Filament\Concerns;

use App\Models\Event;

/**
 * For a relation manager on the Event edit page: once the event is archived,
 * its tabs stay visible (the history is the point) but read-only. Filament
 * denies Create/Edit/Delete/bulk-delete server-side when isReadOnly() is
 * true, so a forged call is refused too. A custom Action (a bulk upload, a
 * comp-request approval) isn't covered by this and must check
 * isOwnerEventArchived() itself.
 */
trait ReadOnlyWhenEventArchived
{
    public function isReadOnly(): bool
    {
        return $this->isOwnerEventArchived() || parent::isReadOnly();
    }

    protected function isOwnerEventArchived(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event && $owner->isArchived();
    }
}
