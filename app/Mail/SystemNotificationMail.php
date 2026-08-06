<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SystemNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $heading,
        public ?string $detail,
        public string $actionUrl,
        public string $actionLabel = 'Open in ATS',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: str($this->heading)->limit(150)->toString());
    }

    public function content(): Content
    {
        return new Content(view: 'mail.system-notification');
    }

    public function attachments(): array
    {
        return [];
    }
}
