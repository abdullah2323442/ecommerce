<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A plain status message by email: the subject is the message, the body adds the link when there is one. */
class StatusMessage extends Mailable
{
    public function __construct(public string $text, public ?string $link = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->text);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e($this->text).'</p>'.($this->link ? '<p><a href="'.e($this->link).'">'.e($this->link).'</a></p>' : ''));
    }
}
