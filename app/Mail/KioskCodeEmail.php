<?php

namespace App\Mail;

use App\Models\Member;
use App\Models\MembershipSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A member's kiosk QR code, embedded in the email itself (not linked: their
 * phone can't reach this server). Deliberately NOT ShouldQueue -- this app
 * runs no queue worker; see EventEndedSummary.
 */
class KioskCodeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Member $member,
        public string $qrPng,
    ) {}

    public function envelope(): Envelope
    {
        $org = MembershipSetting::current()->org_name;

        return new Envelope(
            subject: filled($org)
                ? __(':org — your kiosk check-in code', ['org' => $org])
                : __('Your kiosk check-in code'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kiosk-code',
            with: ['orgName' => MembershipSetting::current()->org_name],
        );
    }
}
