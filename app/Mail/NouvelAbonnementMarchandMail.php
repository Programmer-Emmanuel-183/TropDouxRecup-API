<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NouvelAbonnementMarchandMail extends Mailable
{
    use Queueable, SerializesModels;

    public $marchand;
    public $abonnement;
    public $paiement;

    public function __construct($marchand, $abonnement, $paiement)
    {
        $this->marchand = $marchand;
        $this->abonnement = $abonnement;
        $this->paiement = $paiement;
    }

    public function build()
    {
        return $this->subject('Nouvel abonnement souscrit')
                    ->markdown('emails.nouvel_abonnement_marchand');
    }
}