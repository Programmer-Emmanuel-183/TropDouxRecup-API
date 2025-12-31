<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CompteMarchandDesactive extends Mailable
{
    use Queueable, SerializesModels;

    public $marchand;

    public function __construct($marchand)
    {
        $this->marchand = $marchand;
    }

    public function build()
    {
        return $this->subject('Votre compte marchand a été désactivé')
                    ->markdown('emails.marchand_desactive');
    }
}

