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

    $status = $request->input('status');
    $amount = (float) $request->input('amount');

    /*
    |--------------------------------------------------------------------------
    | 1️⃣ ABONNEMENT (match avec verify)
    |--------------------------------------------------------------------------
    */
    $paiementAbonnement = PaiementAbonnement::where('data->data->reference', $depositId)->first();

    if ($paiementAbonnement) {

        if ($paiementAbonnement->statut === 'completed') {
            return response()->json([
                'success' => true,
                'message' => 'Déjà complété'
            ]);
        }

        if ($status === 'FAILED') {
            $paiementAbonnement->update([
                'statut' => 'failed',
                'data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Paiement échoué'
            ]);
        }

        if ($status !== 'COMPLETED') {
            $paiementAbonnement->update([
                'statut' => 'pending',
                'data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Paiement en cours'
            ]);
        }

        if ((int)$amount !== (int)$paiementAbonnement->prix) {
            return response()->json([
                'success' => false,
                'message' => 'Montant incohérent'
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

            $fin = match ($abonnement->duree) {
                'semaine' => now()->addWeek(),
                'mois' => now()->addMonth(),
                'trimestre' => now()->addMonths(3),
                'semestre' => now()->addMonths(6),
                'annee' => now()->addYear(),
                default => null
            };

            $marchand->update([
                'id_abonnement' => $abonnement->id,
                'fin_abonnement' => $fin
            ]);

            Facturation::create([
                'nom_abonnement' => $abonnement->type_abonnement,
                'montant' => $paiementAbonnement->prix,
                'id_user' => $marchand->id
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Callback abonnement traité avec succès'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 2️⃣ COMMANDE (match avec verify)
    |--------------------------------------------------------------------------
    */
    $paiementCommande = PaiementCommande::where('data->data->reference', $depositId)->first();

    if ($paiementCommande) {

        if ($paiementCommande->statut === 'completed') {
            return response()->json([
                'success' => true,
                'message' => 'Déjà complété'
            ]);
        }

        if ($status === 'FAILED') {
            $paiementCommande->update([
                'statut' => 'failed',
                'data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Paiement échoué'
            ]);
        }

        if ($status !== 'COMPLETED') {
            $paiementCommande->update([
                'statut' => 'pending',
                'data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Paiement en cours'
            ]);
        }

        if ((int)$amount !== (int)$paiementCommande->prix) {
            return response()->json([
                'success' => false,
                'message' => 'Montant incohérent'
            ], 422);
        }

        DB::transaction(function () use ($paiementCommande, $request) {

            $paiementCommande->update([
                'statut' => 'completed',
                'data' => $request->all()
            ]);

            $commande = Commande::with('sousCommandes.plat.marchand.abonnement')
                ->find($paiementCommande->id_commande);

            if (!$commande) return;

            // nettoyage panier
            if ($commande->client) {
                Panier::where('id_client', $commande->client->id)
                    ->whereIn('id_plat', $commande->sousCommandes->pluck('id_plat'))
                    ->delete();
            }

            // commissions
            $grouped = $commande->sousCommandes->groupBy(fn($sc) => $sc->plat?->marchand?->id);

            foreach ($grouped as $marchandId => $items) {

                if (!$marchandId) continue;

                $marchand = $items->first()->plat->marchand;

                $commissionPercent = Commission::first()?->pourcentage ?? 0;

                if ($marchand->abonnement) {
                    $commissionPercent = match ($marchand->abonnement->type_abonnement) {
                        'premium' => CommissionPremium::first()?->pourcentage ?? 0,
                        'entreprise' => CommissionEntreprise::first()?->pourcentage ?? 0,
                        default => $commissionPercent
                    };
                }

                $total = $items->sum(fn($sc) =>
                    ($sc->plat->prix_reduit ?? 0) * $sc->quantite_plat
                );

                $adminGain = ($total * $commissionPercent) / 100;
                $marchandGain = $total - $adminGain;

                $marchand->increment('solde_marchand', $marchandGain);

                foreach ($items as $sc) {
                    $sc->update(['commission' => $commissionPercent]);
                }

                Admin::where('role', 2)->first()?->increment('solde', $adminGain);
            }

            $commande->update(['statut' => 'pending']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Callback commande traité avec succès'
        ]);
    }

    return response()->json([
        'success' => false,
        'message' => 'Aucun paiement correspondant trouvé'
    ], 404);
}
}