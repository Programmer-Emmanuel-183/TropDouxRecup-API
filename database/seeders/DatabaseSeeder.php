<?php

namespace Database\Seeders;

use App\Models\Abonnement;
use App\Models\ActivationCompte;
use App\Models\Admin;
use App\Models\Avantage;
use App\Models\Commission;
use App\Models\CommissionEntreprise;
use App\Models\CommissionPremium;
use App\Models\Commune;
use App\Models\Time;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        DB::transaction(function () {

            /*
            |--------------------------------------------------------------------------
            | 1️⃣ Création des abonnements
            |--------------------------------------------------------------------------
            */

            $debutant = Abonnement::create([
                'id' => (string) Str::uuid(),
                'type_abonnement' => 'debutant',
                'description' => 'Pour commencer sur TropDouxRecup',
                'montant' => 0,
                'duree' => null,
            ]);

            $premium = Abonnement::create([
                'id' => (string) Str::uuid(),
                'type_abonnement' => 'premium',
                'description' => 'Les restaurants les plus populaires auprès des clients actifs',
                'montant' => 10000,
                'duree' => 'mois',
            ]);

            $entreprise = Abonnement::create([
                'id' => (string) Str::uuid(),
                'type_abonnement' => 'entreprise',
                'description' => 'Pour les grands restaurants',
                'montant' => 20000,
                'duree' => 'trimestre',
            ]);

            /*
            |--------------------------------------------------------------------------
            | 2️⃣ Création des avantages (UNE SEULE FOIS)
            |--------------------------------------------------------------------------
            */

            $avantages = collect([
                'Plats par jour' => ['value' => 10],
                'Statistiques de base' => [],

                'Plats à volonté' => [],
                // 'Assistance prioritaire' => [],
                'Statistiques avancées' => [],
                // 'Présentée dans l’application' => [],
                // 'Insigne premium' => [],

                'Toutes les fonctionnalités premium' => [],
                // 'Assistance dédiée 24h/24 et 7j/7' => [],
                // 'Plusieurs emplacements' => [],
                'Tableau de bord personnalisé' => [],
            ])->map(function ($data, $nom) {
                return Avantage::create(array_merge([
                    'id' => (string) Str::uuid(),
                    'nom_avantage' => $nom,
                ], $data));
            });

            /*
            |--------------------------------------------------------------------------
            | 3️⃣ Association avantages ↔ abonnements
            |--------------------------------------------------------------------------
            */

            // 🔹 Débutant
            $debutant->avantages()->sync([
                $avantages['Plats par jour']->id,
                $avantages['Statistiques de base']->id,
            ]);

            // 🔹 Premium
            $premium->avantages()->sync([
                $avantages['Plats à volonté']->id,
                // $avantages['Assistance prioritaire']->id,
                $avantages['Statistiques avancées']->id,
                // $avantages['Présentée dans l’application']->id,
                // $avantages['Insigne premium']->id,
            ]);

            // 🔹 Entreprise
            $entreprise->avantages()->sync([
                $avantages['Toutes les fonctionnalités premium']->id,
                $avantages['Plats à volonté']->id,
                // $avantages['Assistance prioritaire']->id,
                $avantages['Statistiques avancées']->id,
                // $avantages['Présentée dans l’application']->id,
                // $avantages['Insigne premium']->id,
                // $avantages['Assistance dédiée 24h/24 et 7j/7']->id,
                // $avantages['Plusieurs emplacements']->id,
                $avantages['Tableau de bord personnalisé']->id,
            ]);

        });

        $this->command->info('✔ Abonnements et avantages créés et liés avec succès');


        //Ajout de communes
        $communes = [
            'Abobo',
            'Adjamé',
            'Attécoubé',
            'Cocody',
            'Koumassi',
            'Marcory',
            'Plateau',
            'Port-Bouët',
            'Treichville',
            'Yopougon',
            'Songon',
            'Bingerville',
            'Anyama'
        ];

        foreach ($communes as $localite) {
            $commune = new Commune();
            $commune->id = (string) Str::uuid();
            $commune->localite = $localite;
            $commune->save();
        }
        $this->command->info("     - Quelques communes crees");

        //Ajout du super admin
        $admin = new Admin();
        $admin->id = (string) Str::uuid();
        $admin->nom_admin = 'Administrateur';
        $admin->email_admin = 'administrateur@gmail.com';
        $admin->tel_admin = '0102030405';
        $admin->password_admin = Hash::make('admin123');
        $admin->role = 2;
        $admin->save();
        $this->command->info("     - Super Admin créé");

        //Commission abonnement débutant
        $commission = new Commission();
        $commission->id = (string) Str::uuid();
        $commission->pourcentage = 15;
        $commission->save();
        $this->command->info("     - Commission debutant crée");

        //Commission abonnement premium
        $commission = new CommissionPremium();
        $commission->id = (string) Str::uuid();
        $commission->pourcentage = 10;
        $commission->save();
        $this->command->info("     - Commission premium crée");

        //Commission abonnement entreprise
        $commission = new CommissionEntreprise();
        $commission->id = (string) Str::uuid();
        $commission->pourcentage = 7;
        $commission->save();
        $this->command->info("     - Commission entreprise crée");

        $activation = new ActivationCompte();
        $activation->id = (string) Str::uuid();
        $activation->activate = true;
        $activation->save();
        $this->command->info("     - Choix d’activation par defaut cré");

        $time = new Time();
        $time->id = (string) Str::uuid();
        $time->time_disabled = '23:00:00';
        $time->time_enabled = '08:00:00';
        $time->save();
        $this->command->info("     - Time pour desactiver les plats et vider les paniers");
    }
}
