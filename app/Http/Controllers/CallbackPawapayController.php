<?php

namespace App\Http\Controllers;

use App\Mail\NouvelAbonnementMarchandMail;
use App\Mail\NouvelleCommandePayee;
use App\Models\Abonnement;
use App\Models\Admin;
use App\Models\Commande;
use App\Models\Commission;
use App\Models\CommissionEntreprise;
use App\Models\CommissionPremium;
use App\Models\Facturation;
use App\Models\Marchand;
use App\Models\Notification;
use App\Models\PaiementAbonnement;
use App\Models\PaiementCommande;
use App\Models\Panier;
use App\Models\Plat;
use App\Models\SousCommande;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CallbackPawapayController extends Controller
{
    public function callback_pawapay(Request $request)
    {
        $depositId = $request->input('depositId');

        if (!$depositId) {
            return response()->json([
                'success' => false,
                'message' => 'depositId manquant'
            ], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Vérifier si c'est un PaiementAbonnement
        |--------------------------------------------------------------------------
        */
        $paiementAbonnement = PaiementAbonnement::find($depositId);

        if ($paiementAbonnement) {

            if ($paiementAbonnement->statut === 'completed') {
                return response()->json([
                    'success' => true,
                    'message' => 'Paiement déjà traité'
                ], 200);
            }

            $status = $request->input('status');
            $amount = $request->input('amount');

            if ($status === 'FAILED') {
                $paiementAbonnement->update([
                    'statut' => 'failed',
                    'data' => $request->all()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Paiement échoué'
                ], 200);
            }

            if ($status !== 'COMPLETED') {
                $paiementAbonnement->update([
                    'statut' => 'pending',
                    'data' => $request->all()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Paiement non finalisé'
                ], 200);
            }

            if (!$amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Montant manquant'
                ], 422);
            }

            $amountInt = (int) round((float) $amount);

            if ($amountInt !== (int) $paiementAbonnement->prix) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incohérence du montant'
                ], 422);
            }

            DB::transaction(function () use ($paiementAbonnement, $request) {

                $paiementAbonnement->update([
                    'statut' => 'completed',
                    'data' => $request->all()
                ]);

                $marchand = Marchand::find($paiementAbonnement->id_marchand);
                $abonnement = Abonnement::find($paiementAbonnement->id_abonnement);

                if (!$marchand || !$abonnement) return;

                switch ($abonnement->duree) {
                    case 'semaine':
                        $duree = now()->addWeek();
                        break;
                    case 'mois':
                        $duree = now()->addMonth();
                        break;
                    case 'trimestre':
                        $duree = now()->addMonths(3);
                        break;
                    case 'semestre':
                        $duree = now()->addMonths(6);
                        break;
                    case 'annee':
                        $duree = now()->addYear();
                        break;
                    default:
                        $duree = null;
                }

                $marchand->update([
                    'id_abonnement' => $abonnement->id,
                    'fin_abonnement' => $duree
                ]);

                $facturation = new Facturation();
                $facturation->nom_abonnement = $abonnement->type_abonnement;
                $facturation->montant = $paiementAbonnement->prix;
                $facturation->id_user = $marchand->id;
                $facturation->save();

                $admins = Admin::pluck('email_admin')->toArray();

                $admin = Admin::where('role', 2)->first();
                if(!$admin){
                    return response()->json([
                        'success' => false,
                        'message' => 'Administrateur introuvable'
                    ], 404);
                }

                $admin->solde += $paiementAbonnement->prix;
                $admin->save(); 

                // Mail::to($admins)->send(
                //     new NouvelAbonnementMarchandMail($marchand, $abonnement, $paiementAbonnement)
                // );

                if ($marchand->device_token) {

                    $notification = new Notification();
                    $notification->type = 'abonnement';
                    $notification->title = 'Abonnement activé 🎉';
                    $notification->body = "Votre abonnement {$abonnement->type_abonnement} a été activé.";
                    $notification->role = 'marchand';
                    $notification->id_user = $marchand->id;
                    $notification->save();

                    app(PushNotifController::class)->sendPush($notification);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Callback abonnement traité avec succès'
            ], 200);
        }

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Sinon vérifier si c'est un PaiementCommande
        |--------------------------------------------------------------------------
        */
        $paiementCommande = PaiementCommande::find($depositId);

        if ($paiementCommande) {

            if ($paiementCommande->statut === 'completed') {
                return response()->json([
                    'success' => true,
                    'message' => 'Paiement déjà traité'
                ], 200);
            }

            $status = $request->input('status');
            $amount = (int) round((float) $request->input('amount'));

            if ($status === 'FAILED') {
                $paiementCommande->update([
                    'statut' => 'failed',
                    'data' => $request->all()
                ]);

                Commande::where('id', $paiementCommande->id_commande)->update(['statut' => 'failed']);
                SousCommande::where('id_commande', $paiementCommande->id_commande)
                    ->update(['statut' => 'failed']);

                return response()->json([
                    'success' => false,
                    'message' => 'Paiement échoué'
                ], 200);
            }

            if ($status !== 'COMPLETED') {
                $paiementCommande->update([
                    'statut' => 'pending',
                    'data' => $request->all()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Paiement non finalisé'
                ], 200);
            }

            if ($amount !== (int)$paiementCommande->prix) {
                return response()->json([
                    'success' => false,
                    'message' => 'Montant incohérent'
                ], 422);
            }

            DB::transaction(function () use (&$paiementCommande, $request) {

                $paiementCommande = PaiementCommande::lockForUpdate()->find($paiementCommande->id);

                if (!$paiementCommande || $paiementCommande->statut === 'completed') {
                    return;
                }

                $paiementCommande->update([
                    'statut' => 'completed',
                    'data' => $request->all()
                ]);

                $commande = Commande::with([
                    'sousCommandes.plat.marchand.abonnement'
                ])->find($paiementCommande->id_commande);

                if (!$commande) {
                    throw new \Exception("Commande introuvable.");
                }

                foreach ($commande->sousCommandes as $sousCommande) {
                    $plat = Plat::lockForUpdate()->find($sousCommande->id_plat);

                    if (!$plat) {
                        throw new \Exception("Le plat n'existe plus.");
                    }

                    if ($plat->quantite_disponible < $sousCommande->quantite_plat) {
                        throw new \Exception("Stock insuffisant pour {$plat->nom_plat}.");
                    }

                    $plat->decrement('quantite_disponible', $sousCommande->quantite_plat);
                }

                $commande->update(['statut' => 'pending']);
                SousCommande::where('id_commande', $commande->id)
                    ->update(['statut' => 'pending']);

                Panier::where('id_client', $paiementCommande->id_client)
                    ->whereIn('id_plat', $commande->sousCommandes->pluck('id_plat'))
                    ->delete();

                $admin = Admin::where('role', 2)->first();

                $grouped = $commande->sousCommandes->groupBy(function ($sc) {
                    return $sc->plat?->marchand?->id;
                });

                foreach ($grouped as $marchandId => $sousCommandes) {

                    if (!$marchandId) continue;

                    $marchand = $sousCommandes->first()->plat->marchand;

                    if ($marchand->abonnement) {
                        switch ($marchand->abonnement->type_abonnement) {
                            case 'premium':
                                $commissionPercent = CommissionPremium::first()?->pourcentage ?? 0;
                                break;
                            case 'entreprise':
                                $commissionPercent = CommissionEntreprise::first()?->pourcentage ?? 0;
                                break;
                            default:
                                $commissionPercent = Commission::first()?->pourcentage ?? 0;
                        }
                    } else {
                        $commissionPercent = Commission::first()?->pourcentage ?? 0;
                    }

                    $total = $sousCommandes->sum(function ($sc) {
                        return ($sc->plat->prix_reduit ?? 0) * $sc->quantite_plat;
                    });

                    $commissionAdmin = ($total * $commissionPercent) / 100;
                    $partMarchand = $total - $commissionAdmin;

                    $marchand->increment('solde_marchand', $partMarchand);

                    foreach ($sousCommandes as $sc) {
                        $sc->update(['commission' => $commissionPercent]);
                    }

                    $codeCommande = $sousCommandes->first()->code_commande;
                    $alreadyCredited = Transaction::where('libelle', "Commande #{$codeCommande}")
                        ->where('id_user', $marchand->id)
                        ->exists();

                    if (!$alreadyCredited) {
                        Transaction::create([
                            'amount'   => $partMarchand,
                            'type'     => 'credit',
                            'libelle'  => "Commande #{$codeCommande}",
                            'id_user'  => $marchand->id,
                        ]);
                    }

                    if ($admin) {
                        $admin->increment('solde', $commissionAdmin);
                    }
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Callback commande traité avec succès'
            ], 200);
        }

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ Aucun paiement trouvé
        |--------------------------------------------------------------------------
        */
        return response()->json([
            'success' => false,
            'message' => 'Aucun paiement correspondant trouvé'
        ], 404);
    }
}
