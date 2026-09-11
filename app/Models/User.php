<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Capability;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password', 'role', 'active', 'member_id', 'default_register_id', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->active;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function defaultRegister(): BelongsTo
    {
        return $this->belongsTo(Register::class, 'default_register_id');
    }

    public function createdEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'created_by');
    }

    public function recordedSubscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'recorded_by');
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(Attendance::class, 'checked_in_by');
    }

    public function recordedVouchers(): HasMany
    {
        return $this->hasMany(Voucher::class, 'recorded_by');
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(UserCapability::class);
    }

    /**
     * Oversight view of what this staff member has written into the
     * behavior-note audit trail -- "who is entering notes," not "what notes
     * exist on a member" (that's Member::behaviorNotes()). See
     * BehaviorNotesWrittenRelationManager, Admin+ via UserResource.
     */
    public function writtenBehaviorNotes(): HasMany
    {
        return $this->hasMany(AttendanceBehaviorNote::class, 'created_by');
    }

    public function hasCapability(Capability $capability): bool
    {
        return $this->capabilities()->where('capability', $capability)->exists();
    }

    /**
     * Volunteer+ staff don't pass through check-in the way a patron does,
     * so Attendance says nothing about whether they're in the building --
     * but an unexpired session does. "Signed in" is deliberately defined as
     * "session not yet expired" (sessions.last_activity within
     * config('session.lifetime')), not a separate, shorter activity window
     * -- if Laravel still considers the session valid, the person is still
     * authenticated into the system right now. See ActivePatrons, which
     * notes these staff alongside the checked-in patron roster.
     */
    public static function signedInStaffQuery(): Builder
    {
        $activeSince = now()->subMinutes(config('session.lifetime'))->getTimestamp();

        $staffRoles = collect(Role::cases())
            ->filter(fn (Role $role) => $role->atLeast(Role::Volunteer))
            ->map(fn (Role $role) => $role->value);

        return static::query()
            ->where('active', true)
            ->whereIn('role', $staffRoles)
            ->whereIn('id', DB::table('sessions')
                ->whereNotNull('user_id')
                ->where('last_activity', '>=', $activeSince)
                ->pluck('user_id'));
    }
}
