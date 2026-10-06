<?php

namespace App\Filament\Concerns;

use App\Models\Event;

/**
 * For a relation manager on an event page: once the event is archived, its
 * tabs stay visible (the history is the point) but read-only. Filament
 * denies Create/Edit/Delete/bulk-delete server-side when isReadOnly() is
 * true, so a forged call is refused too. A custom Action (a bulk upload, a
 * comp-request approval) isn't covered by this and must check
 * isOwnerEventArchived() itself.
 *
 * Archiving is the only thing that locks these tabs. Filament would also make
 * them read-only on ViewEvent (its default for view pages), but that page is
 * how Managers reach the Prepay and Comp lists they run, so this doesn't defer
 * to parent::isReadOnly(). Each tab's own actions still check their policies
 * and gates.
 */
trait ReadOnlyWhenEventArchived
{
    public function isReadOnly(): bool
    {
        return $this->isOwnerEventArchived();
    }

    protected function isOwnerEventArchived(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Event && $owner->isArchived();
    }
}
