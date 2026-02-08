<?php

namespace App\Console\Commands;

use App\Models\Plat;
use App\Models\Time;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HandleTimeCommand extends Command
{
    protected $signature = 'times:handle-time';

    protected $description = 'Désactive les plats et vide les paniers selon l’intervalle horaire défini par l’administrateur';

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
            $hasActivePlats = Plat::where('is_active', true)->exists();

            if ($hasActivePlats) {

                // ⛔ Désactivation globale des plats
                Plat::where('is_active', true)->update([
                    'is_active' => false
                ]);

                // 🧹 Vidage des paniers (UNE SEULE FOIS)
                DB::table('paniers')->truncate();
                // OU
                // DB::table('panier_items')->truncate();

                $this->info('⛔ Plats désactivés et paniers vidés (intervalle fermé).');

            } else {
                $this->info('⏸️ Intervalle fermé — plats déjà désactivés.');
            }

        } else {
            $this->info('✅ Intervalle ouvert — aucune action requise.');
        }

        return Command::SUCCESS;
    }
}
