<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;


class MarchandInscriptionMail extends Mailable
{
    use Queueable, SerializesModels;

    public $marchand;
    public $type; // active ou pending

    public function __construct($marchand, $type)
    {
        $this->marchand = $marchand;
        $this->type = $type;
    }

    public function build()
    {
        return $this->subject('Nouvelle inscription marchand')
                    ->markdown('emails.marchand_inscription');
    }
}