<?php

namespace App\Console\Commands;

use App\Models\Plat;
use App\Models\Time;
use App\Models\Marchand;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HandleTimeCommand extends Command
{
    protected $signature = 'times:handle-time';
    protected $description = 'Désactive les plats et envoie des notifications selon l’intervalle horaire';

    public function handle()
    {
        $time = Time::first();

        if (!$time) {
            $this->error('Aucune configuration horaire trouvée.');
            return Command::FAILURE;
        }

        $now = Carbon::now()->format('H:i:s');

        $disabled = $time->time_disabled;
        $enabled  = $time->time_enabled;

        /**
         * 🔹 Gestion intervalle normal ET intervalle de nuit
         * Ex :
         * 08:00 → 18:00
         * 22:00 → 06:00
         */
        $isInDisabledInterval = $disabled < $enabled
            ? ($now >= $disabled && $now < $enabled)
            : ($now >= $disabled || $now < $enabled);

        if ($isInDisabledInterval) {

            // 🔎 Vérifier s’il reste des plats actifs
            $activePlats = Plat::where('is_active', true)->get();

            if ($activePlats->isNotEmpty()) {

                // ⛔ Désactivation globale des plats
                Plat::where('is_active', true)->update([
                    'is_active' => false
                ]);

                // 🧹 Vidage des paniers
                DB::table('paniers')->truncate();

                $this->info('⛔ Plats désactivés et paniers vidés (intervalle fermé).');

                // 🔔 Notifications aux marchands ayant des plats désactivés
                $marchandIds = $activePlats->pluck('id_marchand')->unique();

                $marchands = Marchand::whereIn('id', $marchandIds)->get();

                foreach ($marchands as $marchand) {
                    // Vérifier doublon pour éviter spam
                    $exists = Notification::where('id_user', $marchand->id)
                        ->where('type', 'plat')
                        ->whereDate('created_at', Carbon::today())
                        ->exists();

                    if ($exists) continue;

                    $notif = Notification::create([
                        'type' => 'disabled_dish',
                        'title' => 'Plats désactivés ⏸️',
                        'body' => 'Vos plats ont été désactivés temporairement en raison de l’intervalle horaire défini.',
                        'role' => 'marchand',
                        'id_user' => $marchand->id,
                    ]);

                    if ($marchand->device_token) {
                        app(\App\Http\Controllers\PushNotifController::class)
                            ->sendPush($notif);
                    }
                }

            } else {
                $this->info('⏸️ Intervalle fermé — plats déjà désactivés.');
            }

        } else {
            $this->info('✅ Intervalle ouvert — aucune action requise.');
        }

        return Command::SUCCESS;
    }
}
