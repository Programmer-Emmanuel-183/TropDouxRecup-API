<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMarchandMail extends Mailable
{
    use Queueable, SerializesModels;

    public $marchand;
    public string $token;

    public function __construct($marchand, string $token)
    {
        $this->marchand = $marchand;
        $this->token = $token;
    }

    public function build()
    {
        return $this
            ->subject("Reinitialisation de mot de passe")
            ->markdown('emails.reset-password-marchand');
    }
}
