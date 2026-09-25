<x-screen-instructions :title="__('How to use Paperwork Types')">
    <p>{{ __('The forms and waivers the club keeps on file for members. What each member has actually signed is on their Paperwork & waivers tab on Members.') }}</p>
    <p>{!! __('Turn on <strong>Required</strong> for paperwork every member is expected to have. Set <strong>Renews every (months)</strong> for one that expires (12 = yearly), or leave it blank for a one-time form.') !!}</p>
    <p>{!! __('Set <strong>Gates add-on</strong> to make an add-on depend on this paperwork (e.g. Pool and the Pool Waiver). Without valid paperwork, the add-on is dropped from the member\'s price at check-in and they can\'t buy a day pass for it. The desk can record a new signature on the spot.') !!}</p>
    <p>{{ __('Deactivate a type the club no longer uses rather than deleting it, so members\' signing history stays intact.') }}</p>
</x-screen-instructions>
