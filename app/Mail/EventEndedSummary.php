<?php

namespace App\Mail;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Deliberately NOT ShouldQueue -- this app has no queue worker
 * (QUEUE_CONNECTION=database, nothing processing it), the same reason
 * Filament notifications here go through Notification::sendNow() instead of
 * ->sendToDatabase(). A plain Mailable isn't queued unless it opts in, so
 * sending this via Mail::send() is synchronous by default with no workaround
 * needed.
 */
class EventEndedSummary extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{checked_in: int, prepaid_no_show: int, revenue: float}  $summary
     */
    public function __construct(
        public Event $event,
        public array $summary,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Event summary: {$this->event->name} — {$this->event->event_date->toFormattedDateString()}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.event-ended-summary',
        );
    }
}
