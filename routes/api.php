<?php

use App\Http\Controllers\AbonnementController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AnalytiqueController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvantageController;
use App\Http\Controllers\CategorieController;
use App\Http\Controllers\CommandeController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\GestionClientMarchandController;
use App\Http\Controllers\LocaliteController;
use App\Http\Controllers\MarchandController;
use App\Http\Controllers\PanierController;
use App\Http\Controllers\PlatController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

//Route d’inscription marchand et client
Route::post('/register/marchand', [AuthController::class, 'register_marchand']);
Route::post('/register/client', [AuthController::class, 'register_client']);

//Verification et renvoi d’OTP marchand
Route::post('/verify/otp', [AuthController::class, 'verify_otp']);
Route::post('/resend/otp', [AuthController::class, 'resend_otp']);

//Connexion marchand et client
Route::post('/login', [AuthController::class, 'login']);


Route::middleware('auth:marchand')->group(function(){
    //Route pour afficher les infos du marchand
    Route::get('/info/profil/marchand', [AuthController::class, 'info_profil_marchand']);
    //Route pour modifier les infos du marchand
    Route::post('/update/profil/marchand', [AuthController::class, 'update_profil_marchand']);
    //Route pour modifier le mot de passe du marchand
    Route::post('/update/password/marchand', [AuthController::class, 'update_password_marchand']);
});

Route::middleware('auth:client')->group(function(){
    //Route pour afficher les infos du client
    Route::get('/info/profil/client', [AuthController::class, 'info_profil_client']);
    //Route pour modifier les infos du client
    Route::post('/update/profil/client', [AuthController::class, 'update_profil_client']);
    //Route pour modifier le mot de passe du client
    Route::post('/update/password/client', [AuthController::class, 'update_password_client']);
});

//Connexion Admin
Route::post('/login/admin', [AuthController::class, 'login_admin']);

//Avantage
Route::middleware('auth:admin')->group(function(){
    Route::post('/ajout/avantage', [AvantageController::class, 'ajout_avantage']);
    Route::post('/update/avantage/{id}', [AvantageController::class, 'update_avantage']);
    Route::post('/delete/avantage/{id}', [AvantageController::class, 'delete_avantage']);
});
//Liste des avantages
Route::get('/avantages', [AvantageController::class, 'liste_avantage']);

//Abonnement
Route::middleware('auth:admin')->group(function(){
    Route::post('/ajout/abonnement', [AbonnementController::class, 'ajout_abonnement']);
    Route::post('/update/abonnement/{id}', [AbonnementController::class, 'update_abonnement']);
    Route::post('/delete/abonnement/{id}', [AbonnementController::class, 'delete_abonnement']);
});
//Liste des abonnements
Route::get('/abonnements', [AbonnementController::class, 'liste_abonnement']);

//Categorie
Route::middleware('auth:admin')->group(function(){
    Route::post('/ajout/categorie', [CategorieController::class, 'ajout_categorie']);
    Route::post('/update/categorie/{id}', [CategorieController::class, 'update_categorie']);
    Route::post('/delete/categorie/{id}', [CategorieController::class, 'delete_categorie']);
});
//Liste des categories
Route::get('/categories', [CategorieController::class, 'liste_categorie']);
Route::get('/categorie/{id}', [CategorieController::class, 'categorie']);

//Plat
Route::middleware('auth:marchand')->group(function(){
    Route::post('/ajout/plat', [PlatController::class, 'ajout_plat']);
    Route::post('/dupliquer/plat/{id}', [PlatController::class, 'duplicate_plat']);
    Route::get('/plat/marchand', [PlatController::class, 'plat_marchand']);
    Route::post('/delete/plat/{id}', [PlatController::class, 'delete_plat']);
    Route::post('/update/plat/{id}', [PlatController::class, 'update_plat']);
});
//Liste des plats
Route::get('/plats', [PlatController::class, 'plats']);

//Afficher un plat
Route::get('/plat/{id}', [PlatController::class, 'plat']);

//Liste des plats recommandés
Route::get('/plats/recommandes', [PlatController::class, 'plat_recommande']);

//Afficher un marchand
Route::get('/marchand/{id}', [MarchandController::class, 'marchand']);

//Afficher les plats disponibles du marchand
Route::get('/plat/diponibles/marchand/{id}', [MarchandController::class, 'plat_disponible']);

//Localite
Route::middleware('auth:admin')->group(function(){
    Route::post('/ajout/localite', [LocaliteController::class, 'ajout_localite']);
    Route::post('/update/localite/{id}', [LocaliteController::class, 'update_localite']);
    Route::post('/delete/localite/{id}', [LocaliteController::class, 'delete_localite']);
});
// Liste des localites
Route::get('/localites', [LocaliteController::class, 'localites']);
//Afficher une localite
Route::get('/localite/{id}', [LocaliteController::class, 'localite']);

//Panier
Route::middleware('auth:client')->group(function(){
    Route::post('/ajout/panier', [PanierController::class, 'ajout_panier']);
    Route::get('/panier', [PanierController::class, 'panier']);
    Route::post('/delete/plat/panier/{id_item}', [PanierController::class, 'delete_plat']);
});

//Commission
Route::post('/update/commission', [CommissionController::class, 'commission_update']);
//Afficher la commission
Route::get('/commission', [CommissionController::class, 'commission']);

//Commande
Route::middleware('auth:client')->group(function(){
    Route::post('/passer/commande', [CommandeController::class, 'passer_commande']);
    Route::get('/commandes/client', [CommandeController::class, 'commandes_client']);
});
//Commandes du marchand
Route::middleware('auth:marchand')->group(function(){
    Route::get('/commandes/marchand', [CommandeController::class, 'commandes_marchand']);
    Route::post('/marquer/recuperer', [CommandeController::class, 'marquer_comme_recupere']);
    Route::get('/commande/{code_commande}', [CommandeController::class, 'sous_commandes_par_code']);
});

//Info general du marchand
Route::get('/general/info', [MarchandController::class, 'general_info'])->middleware('auth:marchand');

//afficher solde du super admin
Route::get('/solde', [AdminController::class, 'afficher_solde'])->middleware('auth:admin');

//Gestion des utilisateurs (Client et Marchand)
Route::middleware('auth:admin')->group(function(){
    //Client
    Route::get('/clients', [GestionClientMarchandController::class, 'liste_client']);
    Route::get('/client/{id}', [GestionClientMarchandController::class, 'client']);
    Route::post('/delete/client/{id}', [GestionClientMarchandController::class, 'delete_client']);

    //Marchand
    Route::get('/marchands', [GestionClientMarchandController::class, 'liste_marchand']);
    Route::get('/marchand/{id}', [GestionClientMarchandController::class, 'marchand']);
    Route::post('/delete/marchand/{id}', [GestionClientMarchandController::class, 'delete_marchand']);
});

//Analytique marchand
Route::get('/analytique', [AnalytiqueController::class, 'analytique_marchand'])->middleware('auth:marchand');

//Gestion de sous administrateur
Route::middleware('auth:admin')->group(function(){
    Route::post('/ajout/sous/admin', [AuthController::class, 'ajout_sub_admin']);
    Route::get('/admins', [AuthController::class, 'admins']);
    Route::get('/admin/{id}', [AuthController::class, 'admin']);
    Route::post('/delete/admin/{id}', [AuthController::class, 'delete_admin']);
});

//Affichage de toutes les commandes
Route::get('/commandes', [CommandeController::class, 'liste_commandes'])->middleware('auth:admin');


Route::get('/info/solde/marchand', [MarchandController::class, 'info_solde'])->middleware('auth:marchand');