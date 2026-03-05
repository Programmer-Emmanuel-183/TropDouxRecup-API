<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Commande;
use App\Models\Client;
use App\Models\User;

class NouvelleCommandePayee extends Mailable
{
    use Queueable, SerializesModels;

    public $commande;
    public $client;

    public function __construct(Commande $commande, User $client)
    {
        $this->commande = $commande;
        $this->client = $client;
    }

    public function build()
    {
        return $this->subject("Nouvelle commande payée 🎉")
                    ->markdown('emails.commande_payee');
    }
}