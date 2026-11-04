<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AttendanceBehaviorNote;
use App\Models\BehaviorNoteRetraction;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one way a behavior note is taken back. The note row is never edited or
 * deleted: a retraction is its own append-only row (who, when, why). A
 * retracted note drops off Active Patrons but stays on the Manager+ audit
 * trails, marked retracted.
 *
 * Manager+ can retract any note; the note's author (Volunteer+) can retract
 * their own. A reason is always required. Like writing a note, it needs the
 * behavior-notes feature flag on.
 */
class BehaviorNoteRetractionService
{
    public function refusalReason(AttendanceBehaviorNote $note, User $user): ?string
    {
        if (! MembershipSetting::current()->behavior_notes_enabled) {
            return __('Behavior notes are turned off.');
        }

        if ($note->isRetracted()) {
            return __('This behavior note has already been retracted.');
        }

        $isAuthor = $note->created_by === $user->id && $user->role->atLeast(Role::Volunteer);

        if (! $isAuthor && ! $user->role->atLeast(Role::Manager)) {
            return __('Only the note\'s author or a Manager or above can retract it.');
        }

        return null;
    }

    public function retract(AttendanceBehaviorNote $note, User $by, string $reason): BehaviorNoteRetraction
    {
        abort_if(blank($reason), 422, __('A reason is required to retract a behavior note.'));

        return DB::transaction(function () use ($note, $by, $reason): BehaviorNoteRetraction {
            $locked = AttendanceBehaviorNote::whereKey($note->id)->lockForUpdate()->firstOrFail();

            $refusal = $this->refusalReason($locked, $by);
            abort_if($refusal !== null, 403, $refusal ?? '');

            return BehaviorNoteRetraction::create([
                'attendance_behavior_note_id' => $locked->id,
                'reason' => trim($reason),
                'retracted_by' => $by->id,
            ]);
        });
    }
}
