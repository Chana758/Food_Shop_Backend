<?php

namespace App\Mail;

use App\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Contact $contact)
    {
    }

    public function build()
    {
        return $this->subject('Re: ' . ($this->contact->subject ?: 'Your message to Khmer-Fresh'))
            ->view('emails.contact-reply')
            ->with([
                'name'         => $this->contact->name,
                'originalMsg'  => $this->contact->message,
                'replyMessage' => $this->contact->reply_message,
            ]);
    }
}