<?php

namespace App\Filament\Concerns;

use App\Exceptions\GuestRefused;
use App\Models\Category;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PaperworkType;
use App\Models\Register;
use App\Services\AdmissionPolicy;
use App\Services\GuestRegistrationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The Check-In Desk's member-detail actions: promote a Prospective, record
 * new paperwork or a gated-add-on waiver, change a preferred name, and
 * register a guest. Pulled out of CheckIn so the page file holds the
 * check-in flow; behaviour is unchanged.
 *
 * Relies on the host page for getSelectedMember(), getSelectedEvent(),
 * getExistingAttendance(), haltForTraining(), the form and $memberPickerVersion.
 */
trait CapturesMemberIdentity
{
    /**
     * Active PaperworkTypes that gate an add-on and that the selected member
     * has no valid signing for -- the Pool Waiver, at launch. Backs both the
     * warning lines below and recordGatedPaperworkAction().
     *
     * Only for an add-on that's actually in play: one still on sale, or one
     * tonight's event still charges for (a pool_fee set before the Pool flag
     * went off). With Pool switched off there's nothing to sign for, so no
     * warning and no "Record a waiver signature" button.
     *
     * @return Collection<int, PaperworkType>
     */
    public function gatedPaperworkTypesMissing(?Member $member): Collection
    {
        if (! $member) {
            return collect();
        }

        $event = $this->getSelectedEvent();

        return PaperworkType::query()
            ->where('active', true)
            ->whereNotNull('gates_add_on_id')
            ->with('addOn')
            ->get()
            ->filter(fn (PaperworkType $type): bool => $type->addOn?->active
                && ($type->addOn->isCurrentlyPurchasable() || ($event && $type->addOn->priceFor($event) !== null)))
            ->reject(fn (PaperworkType $type): bool => $member->hasValidPaperwork($type))
            ->values();
    }

    /**
     * One warning line per subscribable add-on the selected member can't
     * currently use because a gating PaperworkType (e.g. the Pool Waiver)
     * is missing or expired -- shown in the member-only section so staff
     * know why the add-on's line and charge are absent. See
     * Member::canUseAddOn() / PricingService.
     *
     * @return string[]
     */
    public function getGatedAddOnWarnings(): array
    {
        return $this->gatedPaperworkTypesMissing($this->getSelectedMember())
            ->map(fn (PaperworkType $type) => __(':addon unavailable — :waiver missing or expired. Not charged; do not admit to :addon until it is renewed.', ['addon' => $type->addOn->name, 'waiver' => $type->name]))
            ->all();
    }

    /**
     * Note lines shown just above the "Sell a subscription or day pass" fold
     * (outside it, so they still show when the fold is left out): a day pass
     * for a priced_per_event add-on (Pool) is only offered once its gating
     * waiver is on file, so purchaseAddOnDayPassAction is simply hidden
     * without it. Rather than leave staff wondering where the button went,
     * this spells out that a signature is the prerequisite -- recorded via
     * recordGatedPaperworkAction in the member section above.
     *
     * @return string[]
     */
    public function getDayPassPaperworkNotes(): array
    {
        return $this->gatedPaperworkTypesMissing($this->getSelectedMember())
            ->filter(fn (PaperworkType $type) => $type->addOn->subscribable && $type->addOn->priced_per_event)
            ->map(fn (PaperworkType $type) => __(':addon day pass — requires a signed :waiver first. Record the signature above to enable it.', ['addon' => $type->addOn->name, 'waiver' => $type->name]))
            ->values()
            ->all();
    }

    // A member who wants a gated add-on (e.g. Pool) but has no valid waiver
    // for it can sign it right at the desk -- mirrors confirmPaperworkAction
    // (standard paperwork on file) and saveAndPromoteAction (Prospective
    // identity). Records a member_paperwork signing as of the chosen date;
    // the add-on's line returns to pricing on the next render. Door+ (the
    // whole page is Door+), same trust level as confirming standard
    // paperwork.
    public function recordGatedPaperworkAction(): Action
    {
        $missing = $this->gatedPaperworkTypesMissing($this->getSelectedMember());

        return Action::make('recordGatedPaperwork')
            ->label(__('Record a waiver signature'))
            ->visible(fn (): bool => $missing->isNotEmpty())
            ->schema([
                Select::make('paperwork_type_id')
                    ->label(__('Waiver'))
                    ->options($missing->pluck('name', 'id')->all())
                    ->default($missing->count() === 1 ? $missing->first()->id : null)
                    ->required(),
                DatePicker::make('signed_on')
                    ->label(__('Signed on'))
                    ->default(now())
                    ->required(),
            ])
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: waiver signature simulated.'))) {
                    return;
                }

                $member = $this->getSelectedMember();
                abort_unless($member, 404);

                // Re-checked server-side: the submitted type must still be an
                // active gating type this member actually lacks valid
                // paperwork for -- a forged id can't create an arbitrary row.
                $type = $this->gatedPaperworkTypesMissing($member)->firstWhere('id', (int) $data['paperwork_type_id']);
                abort_unless($type, 403);

                $member->paperwork()->create([
                    'paperwork_type_id' => $type->id,
                    'signed_on' => $data['signed_on'],
                    'recorded_by' => auth()->id(),
                ]);

                Notification::make()->title(__(':name recorded', ['name' => $type->name]))->success()->send();
            });
    }

    public function saveAndPromoteAction(): Action
    {
        return Action::make('saveAndPromote')
            ->label(__('Save & promote to Irregular'))
            ->schema($this->identityCaptureSchema())
            // Member-only, not $this->getDecision() -- Capture doesn't depend on an
            // event, so this needs to be available the moment a member is selected,
            // before any event is picked (AdmissionPolicy::needsCapture()).
            ->visible(fn (): bool => ($member = $this->getSelectedMember()) && app(AdmissionPolicy::class)->needsCapture($member))
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: promote to Irregular simulated.'))) {
                    return;
                }

                $member = $this->getSelectedMember();
                abort_unless($member, 404);

                $irregular = Category::where('name', 'Irregular')->firstOrFail();

                $member->update([
                    'preferred_name' => $data['preferred_name'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => filled($data['email'] ?? null) ? $data['email'] : null,
                    'email_opt_in' => (bool) ($data['email_opt_in'] ?? false),
                    'dob' => $data['dob'] ?? $member->dob,
                    'category_id' => $irregular->id,
                ]);

                // This is the request's cached member (getSelectedMember()),
                // and its category relation was loaded before the update.
                // Eloquent doesn't drop a loaded relation when its foreign key
                // changes, so without this the card would say Prospective
                // until the next request.
                $member->unsetRelation('category');

                Notification::make()->title(__('Member promoted to Irregular'))->success()->send();
            });
    }

    // What the member goes by, changed on the spot. Hidden unless the
    // change-member-preferred-name gate allows it -- for Door that means the
    // club turned on door_preferred_name_change_enabled. Not logged, same as
    // editing it on the member page. Blank clears it, so displayName()
    // falls back to the next field.
    public function changePreferredNameAction(): Action
    {
        return Action::make('changePreferredName')
            ->label('Change preferred name')
            ->visible(fn (): bool => $this->getSelectedMember() !== null && Gate::allows('change-member-preferred-name'))
            ->fillForm(fn (): array => ['preferred_name' => $this->getSelectedMember()?->preferred_name])
            ->schema([
                TextInput::make('preferred_name')
                    ->label('Preferred name')
                    ->maxLength(60),
            ])
            ->action(function (array $data): void {
                abort_unless(Gate::allows('change-member-preferred-name'), 403);

                $member = $this->getSelectedMember();
                abort_unless($member, 404);

                if ($this->haltForTraining(__('Practice: preferred name change simulated.'))) {
                    return;
                }

                $member->update(['preferred_name' => filled($data['preferred_name'] ?? null) ? trim($data['preferred_name']) : null]);

                // The picker's label can include the preferred name.
                $this->memberPickerVersion++;

                Notification::make()->title(__('Preferred name updated'))->success()->send();
            });
    }

    /**
     * The identity fields Door may write at the desk: shared by the
     * Prospective sign-up and the missing-paperwork re-capture, so the two
     * forms can't drift apart. Raw DOB is asked for only when the member
     * appears under the alcohol-flag age, and never pre-filled -- Door
     * writes DOB but doesn't read it (see docs/BLUEPRINT.md "Roles & permissions").
     *
     * @return array<int, Component>
     */
    private function identityCaptureSchema(): array
    {
        return [
            TextInput::make('preferred_name')->required()->maxLength(60),
            TextInput::make('first_name')->required()->maxLength(60),
            TextInput::make('last_name')->required()->maxLength(60),
            TextInput::make('email')->required(fn (): bool => MembershipSetting::current()->member_email_required)->email()->maxLength(120),
            $this->emailOptInToggle(),
            Checkbox::make('appears_under_21')
                ->label(__('Appears to be under :age', ['age' => MembershipSetting::current()->alcohol_flag_age]))
                ->live(),
            DatePicker::make('dob')
                ->label(__('Date of birth'))
                ->required(fn (Get $get): bool => (bool) $get('appears_under_21'))
                ->visible(fn (Get $get): bool => (bool) $get('appears_under_21')),
        ];
    }

    /**
     * The member's bulk-email consent, asked alongside the email at the
     * desk (sign-up, new paperwork, guest registration) -- the same field
     * as the member form's "OK to email (bulk list)".
     */
    private function emailOptInToggle(): Toggle
    {
        return Toggle::make('email_opt_in')
            ->label(__('OK to email (bulk list)'))
            ->helperText(__('Included in the Members list\'s bulk email export when on and an email address is set.'));
    }

    // New paperwork means re-recording who the member is, not just ticking a
    // box: the same identity form as saveAndPromoteAction(), pre-filled so
    // staff correct only what changed, but with no category change. Saving
    // updates those fields, records a Standard Paperwork signing
    // (member_paperwork) as of today, and clears the flag.
    // AdmissionPolicy::needsPaperworkCapture() decides visibility,
    // member-only for the same reason as saveAndPromoteAction().
    public function confirmPaperworkAction(): Action
    {
        return Action::make('confirmPaperwork')
            ->label(__('Record new paperwork'))
            ->modalDescription(__('Check each detail against the new paperwork and correct anything that has changed.'))
            ->schema($this->identityCaptureSchema())
            ->fillForm(fn (): array => $this->getSelectedMember()?->only(['preferred_name', 'first_name', 'last_name', 'email', 'email_opt_in']) ?? [])
            ->visible(fn (): bool => ($member = $this->getSelectedMember()) && app(AdmissionPolicy::class)->needsPaperworkCapture($member))
            ->action(function (array $data): void {
                if ($this->haltForTraining(__('Practice: paperwork recording simulated.'))) {
                    return;
                }

                $member = $this->getSelectedMember();
                abort_unless($member, 404);
                abort_unless(app(AdmissionPolicy::class)->needsPaperworkCapture($member), 403);

                $member->update([
                    'preferred_name' => $data['preferred_name'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => filled($data['email'] ?? null) ? $data['email'] : null,
                    'email_opt_in' => (bool) ($data['email_opt_in'] ?? false),
                    'dob' => $data['dob'] ?? $member->dob,
                ]);

                if ($standard = PaperworkType::where('name', 'Standard Paperwork')->first()) {
                    $member->paperwork()->create([
                        'paperwork_type_id' => $standard->id,
                        'signed_on' => today(),
                        'recorded_by' => auth()->id(),
                    ]);
                }

                $member->update(['missing_paperwork' => false]);

                Notification::make()->title(__('Paperwork recorded'))->success()->send();
            });
    }

    public function registerGuestAction(): Action
    {
        $sponsor = $this->getSelectedMember();
        $attendance = $this->getExistingAttendance();
        $guests = app(GuestRegistrationService::class);

        return Action::make('registerGuest')
            ->label(__('Register a guest'))
            ->schema([
                TextInput::make('username')
                    ->required()
                    ->maxLength(60)
                    ->unique(table: 'members', column: 'username'),
                TextInput::make('preferred_name')->required()->maxLength(60),
                TextInput::make('first_name')->required()->maxLength(60),
                TextInput::make('last_name')->required()->maxLength(60),
                TextInput::make('email')->email()->required(fn (): bool => MembershipSetting::current()->member_email_required)->maxLength(120),
                $this->emailOptInToggle(),
                Checkbox::make('appears_under_21')
                    ->label(__('Appears to be under :age', ['age' => MembershipSetting::current()->alcohol_flag_age]))
                    ->live(),
                DatePicker::make('dob')
                    ->label(__('Date of birth'))
                    ->required(fn (Get $get): bool => (bool) $get('appears_under_21'))
                    ->visible(fn (Get $get): bool => (bool) $get('appears_under_21')),
            ])
            ->visible(fn (): bool => $guests->canRegister($sponsor, $attendance))
            ->action(function (array $data) use ($sponsor, $guests): void {
                if ($this->haltForTraining(__('Practice: guest registration simulated.'))) {
                    return;
                }

                abort_unless($sponsor, 403);
                $attendance = $this->getExistingAttendance();
                abort_unless($attendance, 403);

                // GuestRegistrationService::register() re-checks arrival,
                // canSponsorGuests() and the guest limit (sponsor locked).
                try {
                    $guest = $guests->register($sponsor, $attendance, $data);
                } catch (GuestRefused $refused) {
                    Notification::make()->title($refused->getMessage())->danger()->send();

                    return;
                }

                $this->form->fill([
                    'event_id' => $this->data['event_id'] ?? null,
                    'member_id' => $guest->id,
                ]);

                Notification::make()->title(__("Registered :guest as :sponsor's guest", ['guest' => "{$guest->first_name} {$guest->last_name}", 'sponsor' => $sponsor->username]))->success()->send();
            });
    }
}
