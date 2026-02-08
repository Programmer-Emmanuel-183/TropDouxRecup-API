<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Marchand;
use App\Models\Notification;
use App\Models\Abonnement;
use Carbon\Carbon;

class HandleAbonnementExpiration extends Command
{
    protected $signature = 'abonnements:handle-expiration';
    protected $description = 'Notifications et expiration des abonnements marchands';

    public function handle()
    {
        $now = Carbon::now();

        // 🔔 Notifications avant expiration
        $joursAvant = [7, 5, 3, 2, 1];

        foreach ($joursAvant as $jour) {

            $dateCible = $now->copy()->addDays($jour)->toDateString();

            $marchands = Marchand::whereNotNull('fin_abonnement')
                ->whereDate('fin_abonnement', $dateCible)
                ->get();

            foreach ($marchands as $marchand) {

                // éviter doublon
                $exists = Notification::where('id_user', $marchand->id)
                    ->where('type', 'abonnement')
                    ->where('body', 'like', "%$jour jour%")
                    ->exists();

                if ($exists) continue;

                $notif = Notification::create([
                    'type' => 'abonnement',
                    'title' => 'Fin d’abonnement proche ⏳',
                    'body' => "Votre abonnement expire dans $jour jour(s). Pensez à le renouveler.",
                    'role' => 'marchand',
                    'id_user' => $marchand->id,
                ]);

                if ($marchand->device_token) {
                    app(\App\Http\Controllers\PushNotifController::class)
                        ->sendPush($notif);
                }
            }
        }

        // ❌ Expiration réelle
        $marchandsExpires = Marchand::whereNotNull('fin_abonnement')
            ->where('fin_abonnement', '<=', $now)
            ->get();

        foreach ($marchandsExpires as $marchand) {

            // abonnement débutant
            $abonnementDebutant = Abonnement::where('type_abonnement', 'debutant')->first();

            $marchand->update([
                'id_abonnement' => $abonnementDebutant?->id,
                'fin_abonnement' => null,
            ]);

            $notif = Notification::create([
                'type' => 'abonnement',
                'title' => 'Abonnement expiré ❌',
                'body' => 'Votre abonnement est expiré. Vous êtes passé au plan débutant.',
                'role' => 'marchand',
                'id_user' => $marchand->id,
            ]);

            if ($marchand->device_token) {
                app(\App\Http\Controllers\PushNotifController::class)
                    ->sendPush($notif);
            }
        }

        return Command::SUCCESS;
    }
}
