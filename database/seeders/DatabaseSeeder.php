<?php

namespace Database\Seeders;

use App\Models\Abonnement;
use App\Models\ActivationCompte;
use App\Models\Admin;
use App\Models\Avantage;
use App\Models\Commission;
use App\Models\Commune;
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

        //Ajout avantage
        $avantage = new Avantage();
        $avantage->id = Str::uuid();
        $avantage->nom_avantage = "Plats par jour";
        $avantage->value = 5;
        $avantage->save();
        $this->command->info("     - Avantage créé");


        // Ajout d’abonnement
        $abonnement = new Abonnement();
        $abonnement->id = Str::uuid();
        $abonnement->type_abonnement = 'debutant';
        $abonnement->description = 'La description de l’abonnement';
        $abonnement->montant = 0;
        $abonnement->duree = null;
        $abonnement->save();

        $this->command->info("     - Abonnement par defaut créé");

        //Associé avantage à abonnement
        // $abonnement->avantages()->attach($avantage->id);
        // $abonnement->avantages()->sync([$avantage->id]);
        DB::table('avantage_abonnement')->insert([
            'id_abonnement' => (string) $abonnement->id,
            'id_avantage' => (string) $avantage->id,
        ]);
        $this->command->info("     - Lié avantage à abonnement.");

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

        //Commission par défaut
        $commission = new Commission();
        $commission->id = (string) Str::uuid();
        $commission->pourcentage = 10;
        $commission->save();
        $this->command->info("     - Commission crée");

        $activation = new ActivationCompte();
        $activation->id = (string) Str::uuid();
        $activation->activate = true;
        $activation->save();
        $this->command->info("     - Choix d’activation par defaut cré");
    }
}
