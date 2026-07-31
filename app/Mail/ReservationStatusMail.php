<?php

namespace App\Mail;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReservationStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public Reservation $reservation;
    public string $status;      // confirmed | rejected | completed
    public ?string $note;       // optional admin message

    public function __construct(Reservation $reservation, string $status, ?string $note = null)
    {
        $this->reservation = $reservation;
        $this->status      = $status;
        $this->note        = $note;
    }

    public function envelope(): Envelope
    {
        $subjects = [
            'confirmed' => 'Your reservation has been confirmed ✅',
            'rejected'  => 'Update on your reservation request',
            'completed' => 'Thank you for dining with us 🍽️',
        ];

        return new Envelope(
            subject: $subjects[$this->status] ?? 'Your reservation status has been updated',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reservation-status',
            with: [
                'reservation' => $this->reservation,
                'status'      => $this->status,
                'note'        => $this->note,
            ],
        );
    }
}