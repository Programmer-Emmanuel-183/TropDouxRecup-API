<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Commande;
use App\Models\PaiementCommande;
use App\Models\Panier;
use App\Models\Plat;
use App\Models\SousCommande;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class PaiementCommandeController extends Controller
{
    /**
     * Initialiser le paiement d'une commande
     */
    public function initialiser_paiement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'marchand_id' => 'required|array',
            'marchand_id.*' => 'exists:marchands,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $client = $request->user();

            // 🔥 Récupération des paniers par marchand
            $paniers = Panier::with(['plat.marchand'])
                ->where('id_client', $client->id)
                ->whereHas('plat', function ($q) use ($request) {
                    $q->whereIn('id_marchand', $request->marchand_id);
                })
                ->get();

            if ($paniers->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun panier trouvé pour ces marchands.'
                ], 404);
            }

            // 🔒 Vérification stock et disponibilité des plats
            foreach ($paniers as $panier) {
                $plat = Plat::whereNull('deleted_at')->where('id', $panier->id_plat)->first();
                if (!$plat) {
                    return response()->json([
                        'success' => false,
                        'message' => "Le plat {$panier->plat->nom_plat} n'est plus disponible"
                    ], 400);
                }
                if ($plat->quantite_disponible < $panier->quantite) {
                    return response()->json([
                        'success' => false,
                        'message' => "Stock insuffisant pour le plat : {$plat->nom_plat}"
                    ], 400);
                }
            }

            // 🧾 Création commande principale
            $commande = new Commande();
            $commande->statut = 'pending';
            $commande->save();

            $totalPrix = 0;
            $totalQuantity = 0;
            $sousCommandes = [];

            foreach ($paniers as $panier) {
                $plat = $panier->plat;

                // 🔻 Mise à jour stock
                $plat->quantite_disponible -= $panier->quantite;
                $plat->save();

                // 🔹 Sous-commande
                $sous = new SousCommande();
                $sous->id_commande = $commande->id;
                $sous->id_client = $client->id;
                $sous->id_plat = $plat->id;
                $sous->id_marchand = $plat->id_marchand;
                $sous->quantite_plat = $panier->quantite;
                $sous->statut = 'pending';
                $sous->commission = 0; // calculé plus tard si nécessaire
                $sous->code_commande = "CMD-" . strtoupper(substr($commande->id, 0, 6));
                $sous->code_qr = 'data:image/svg+xml;base64,' . base64_encode(QrCode::format('svg')->size(200)->generate($sous->code_commande));
                $sous->save();

                $totalPrix += $plat->prix_reduit * $panier->quantite;
                $totalQuantity += $panier->quantite;
                $sousCommandes[] = $sous;
            }

            // 🧹 Nettoyage panier
            Panier::where('id_client', $client->id)
                ->whereHas('plat', function ($q) use ($request) {
                    $q->whereIn('id_marchand', $request->marchand_id);
                })->delete();

            // 🔹 Paiement principal
            $paiement = new PaiementCommande();
            $paiement->id_client = $client->id;
            $paiement->id_commande = $commande->id;
            $paiement->id_marchand = $paniers->first()->plat->id_marchand; // choix du premier marchand pour Pawapay
            $paiement->prix = $totalPrix;
            $paiement->save();

            // 🔹 Payload Pawapay
            $payload = [
                "depositId" => $paiement->id,
                "returnUrl" => config('services.pawapay.return_url'),
                "customerMessage" => "Paiement commande",
                "amountDetails" => [
                    "amount" => (string) $totalPrix,
                    "currency" => "XOF"
                ],
                "language" => "FR",
                "country" => "CIV",
                "reason" => "Commande client",
                "metadata" => [
                    ["orderId" => $paiement->id],
                    ["clientName" => $client->nom_client],
                    ["id_client" => $client->id],
                ]
            ];

            $response = Http::withToken(config('services.pawapay.api_key'))
                ->post('https://api.sandbox.pawapay.io/v2/paymentpage', $payload);

            $result = $response->json();

            if ($response->failed() || ($result['status'] ?? null) === 'REJECTED') {
                return response()->json([
                    'success' => false,
                    'message' => 'Paiement rejeté',
                    'erreur' => $result['failureReason'] ?? 'Erreur inconnue'
                ], 422);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'commande' => [
                        'id' => $commande->id,
                        'orderId' => $sousCommandes[0]->code_commande,
                        'status' => $commande->statut,
                        'createdAt' => $commande->created_at,
                        'totalPriceOrder' => $totalPrix,
                        'orderLength' => $totalQuantity,
                        'dishes' => array_map(function ($sc) {
                            return [
                                'id' => $sc->id_plat,
                                'name' => $sc->plat->nom_plat,
                                'quantity' => $sc->quantite_plat,
                                'unit_price' => $sc->plat->prix_reduit,
                                'code_qr' => $sc->code_qr
                            ];
                        }, $sousCommandes),
                    ],
                    'redirectUrl' => $result['redirectUrl']
                ],
                'message' => 'Paiement initialisé avec succès'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Vérifier le paiement d'une commande
     */
    public function verifier_paiement(Request $request, $depositId){
        $paiement = PaiementCommande::find($depositId);
        if (!$paiement) {
            return response()->json(['success' => false, 'message' => 'Paiement introuvable'], 404);
        }

        // 🔹 Éviter doublon
        if ($paiement->statut === 'completed') {
            return response()->json(['success' => true, 'message' => 'Paiement déjà vérifié'], 200);
        }

        $response = Http::withToken(config('services.pawapay.api_key'))
            ->get("https://api.sandbox.pawapay.io/v2/deposits/{$depositId}");

        $result = $response->json();

        if ($response->failed() || ($result['status'] ?? null) === 'REJECTED') {
            $paiement->update(['statut' => 'failed', 'data' => $result]);
            return response()->json(['success' => false, 'message' => 'Paiement échoué'], 422);
        }

        if (($result['data']['status'] ?? null) !== 'COMPLETED') {
            $paiement->update(['statut' => 'pending', 'data' => $result]);
            return response()->json(['success' => false, 'message' => 'Paiement non finalisé'], 200);
        }

        DB::transaction(function () use ($paiement, $result) {

            $paiement->update(['statut' => 'completed', 'data' => $result]);

            $commande = Commande::with(['sousCommandes.plat.marchand', 'client.abonnement'])
                ->find($paiement->id_commande);

            if (!$commande) return;

            $commande->update(['statut' => 'pending']);

            $client = $commande->client;

            // 🔥 Détermination commission selon abonnement
            $commissionType = 'Commission';
            if ($client && $client->abonnement) {
                switch ($client->abonnement->type_abonnement) {
                    case 'premium':
                        $commissionType = 'CommissionPremium';
                        break;
                    case 'entreprise':
                        $commissionType = 'CommissionEntreprise';
                        break;
                }
            }

            $commissionModel = app("App\\Models\\{$commissionType}");
            $commissionValue = $commissionModel::first()?->pourcentage ?? 0;

            $admin = Admin::where('role', 2)->first();

            foreach ($commande->sousCommandes as $sc) {

                $plat = $sc->plat;
                $marchand = $plat?->marchand;

                if (!$plat || !$marchand) continue;

                $montantTotal = $plat->prix_reduit * $sc->quantite_plat;
                $commissionAdmin = ($montantTotal * $commissionValue) / 100;
                $partMarchand = $montantTotal - $commissionAdmin;

                $marchand->increment('solde_marchand', $partMarchand);

                if ($admin) {
                    $admin->increment('solde', $commissionAdmin);
                }
            }
        });

        $commande = Commande::with(['sousCommandes.plat'])
            ->find($paiement->id_commande);

        $client = $paiement->client;

        if ($client && $client->device_token) {
            $notif = Notification::create([
                'type' => 'commande',
                'title' => 'Paiement validé 🎉',
                'body' => "Votre paiement pour la commande {$commande->id} a été validé.",
                'role' => 'client',
                'id_user' => $client->id
            ]);
            app(PushNotifController::class)->sendPush($notif);
        }

        return response()->json([
            'success' => true,
            'message' => 'Paiement vérifié avec succès',
            'data' => [
                'id' => $paiement->id,
                'statut' => $paiement->statut,
                'prix' => $paiement->prix,
                'commande' => [
                    'id' => $commande->id,
                    'orderId' => $commande->sousCommandes->first()?->code_commande ?? null,
                    'status' => $commande->statut,
                    'createdAt' => $commande->created_at,
                    'totalPriceOrder' => $paiement->prix,
                    'dishes' => $commande->sousCommandes->map(function ($sc) {
                        return [
                            'id' => $sc->id_plat,
                            'name' => $sc->plat->nom_plat ?? '',
                            'quantity' => $sc->quantite_plat,
                            'unit_price' => $sc->plat->prix_reduit ?? 0,
                            'code_qr' => $sc->code_qr
                        ];
                    })
                ]
            ]
        ], 200);
    }


    /**
     * Callback Pawapay pour le paiement des commandes
     */
    public function callback_pawapay(Request $request){
        $depositId = $request->input('depositId');
        if (!$depositId) {
            return response()->json(['success' => false, 'message' => 'depositId manquant'], 400);
        }

        $paiement = PaiementCommande::find($depositId);
        if (!$paiement) {
            return response()->json(['success' => false, 'message' => 'Paiement introuvable'], 404);
        }

        if ($paiement->statut === 'completed') {
            return response()->json(['success' => true, 'message' => 'Paiement déjà traité'], 200);
        }

        $status = $request->input('status');
        $amount = (int) round((float) $request->input('amount'));

        if ($status === 'FAILED') {
            $paiement->update(['statut' => 'failed', 'data' => $request->all()]);
            return response()->json(['success' => false, 'message' => 'Paiement échoué'], 200);
        }

        if ($status !== 'COMPLETED') {
            $paiement->update(['statut' => 'pending', 'data' => $request->all()]);
            return response()->json(['success' => false, 'message' => 'Paiement non finalisé'], 200);
        }

        if ($amount !== (int)$paiement->prix) {
            return response()->json([
                'success' => false,
                'message' => 'Montant incohérent',
                'details' => ['attendu' => $paiement->prix, 'recu' => $amount]
            ], 422);
        }

        DB::transaction(function () use ($paiement, $request) {

            $paiement->update(['statut' => 'completed', 'data' => $request->all()]);

            $commande = Commande::with(['sousCommandes.plat.marchand', 'client.abonnement'])
                ->find($paiement->id_commande);

            if (!$commande) return;

            $commande->update(['statut' => 'pending']);

            $client = $commande->client;

            $commissionType = 'Commission';
            if ($client && $client->abonnement) {
                switch ($client->abonnement->type_abonnement) {
                    case 'premium':
                        $commissionType = 'CommissionPremium';
                        break;
                    case 'entreprise':
                        $commissionType = 'CommissionEntreprise';
                        break;
                }
            }

            $commissionModel = app("App\\Models\\{$commissionType}");
            $commissionValue = $commissionModel::first()?->pourcentage ?? 0;

            $admin = Admin::where('role', 2)->first();

            foreach ($commande->sousCommandes as $sc) {

                $plat = $sc->plat;
                $marchand = $plat?->marchand;

                if (!$plat || !$marchand) continue;

                $montantTotal = $plat->prix_reduit * $sc->quantite_plat;
                $commissionAdmin = ($montantTotal * $commissionValue) / 100;
                $partMarchand = $montantTotal - $commissionAdmin;

                $marchand->increment('solde_marchand', $partMarchand);

                if ($admin) {
                    $admin->increment('solde', $commissionAdmin);
                }
            }
        });

        return response()->json(['success' => true, 'message' => 'Callback traité avec succès'], 200);
    }

}
