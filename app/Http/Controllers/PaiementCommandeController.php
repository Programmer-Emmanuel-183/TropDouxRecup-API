<?php

namespace App\Http\Controllers;

use App\Mail\NouvelleCommandePayee;
use App\Models\Admin;
use App\Models\Commande;
use App\Models\Commission;
use App\Models\CommissionEntreprise;
use App\Models\CommissionPremium;
use App\Models\PaiementCommande;
use App\Models\Panier;
use App\Models\Plat;
use App\Models\SousCommande;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
                $sous->code_commande = "TDR-" . strtoupper(substr($commande->id, 0, 6));
                $sous->code_qr = 'data:image/svg+xml;base64,' . base64_encode(QrCode::format('svg')->size(200)->generate($sous->code_commande));
                $sous->save();

                $totalPrix += $plat->prix_reduit * $panier->quantite;
                $totalQuantity += $panier->quantite;
                $sousCommandes[] = $sous;
            }

            

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
                "returnUrl" => config('services.pawapay.return_url_commande'),
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

            $paiement->update([
                'statut' => 'completed',
                'data' => $result
            ]);

            

            $commande = Commande::with([
                'sousCommandes.plat.marchand.abonnement',
                'client'
            ])->find($paiement->id_commande);

            // Récupérer tous les admins (role = 2)
            $admins = Admin::where('role', 2)->get();

            foreach ($admins as $admin) {
                Mail::to($admin->email_admin)->send(new NouvelleCommandePayee($commande, $commande->client));
            }

            // 🧹 Nettoyage panier UNIQUEMENT si paiement validé
            if ($commande && $commande->client) {
                Panier::where('id_client', $commande->client->id)
                    ->whereIn('id_plat', $commande->sousCommandes->pluck('id_plat'))
                    ->delete();
            }

            if (!$commande) return;

            $commande->update(['statut' => 'pending']);

            $admin = Admin::where('role', 2)->first();

            // 🔥 TOTAL COMMANDE
            $totalCommande = $commande->sousCommandes->sum(function ($sc) {
                return ($sc->plat->prix_reduit ?? 0) * $sc->quantite_plat;
            });

            if ($totalCommande <= 0) return;

            // 🔥 Grouper par marchand
            $groupedByMarchand = $commande->sousCommandes->groupBy(function ($sc) {
                return $sc->plat?->marchand?->id;
            });

            foreach ($groupedByMarchand as $marchandId => $sousCommandes) {

                if (!$marchandId) continue;

                $marchand = $sousCommandes->first()->plat->marchand;

                if (!$marchand) continue;

                // 🔥 Détermination commission selon abonnement DU MARCHAND
                $commissionPercent = 0;

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

                // 🔥 Total pour CE marchand
                $totalMarchand = $sousCommandes->sum(function ($sc) {
                    return ($sc->plat->prix_reduit ?? 0) * $sc->quantite_plat;
                });

                $commissionAdmin = ($totalMarchand * $commissionPercent) / 100;
                $partMarchand = $totalMarchand - $commissionAdmin;

                // 🔥 Incrément solde marchand
                $marchand->increment('solde_marchand', $partMarchand);

                // 🔥 Stocker commission sur sous_commandes
                foreach ($sousCommandes as $sc) {
                    $sc->update([
                        'commission' => $commissionPercent
                    ]);
                }

                if ($admin) {
                    $admin->increment('solde', $commissionAdmin);
                }
            }
        });

        $commande = Commande::with(['sousCommandes.plat'])->find($paiement->id_commande);
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
            'message' => 'Paiement vérifié avec succès'
        ], 200);
    }

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
                'message' => 'Montant incohérent'
            ], 422);
        }

        DB::transaction(function () use ($paiement, $request) {

            $paiement->update([
                'statut' => 'completed',
                'data' => $request->all()
            ]);

            $commande = Commande::with([
                'sousCommandes.plat.marchand.abonnement'
            ])->find($paiement->id_commande);

            if (!$commande) return;

            $commande->update(['statut' => 'pending']);

            $admin = Admin::where('role', 2)->first();

            $groupedByMarchand = $commande->sousCommandes->groupBy(function ($sc) {
                return $sc->plat?->marchand?->id;
            });

            foreach ($groupedByMarchand as $marchandId => $sousCommandes) {

                if (!$marchandId) continue;

                $marchand = $sousCommandes->first()->plat->marchand;

                $commissionPercent = 0;

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

                $totalMarchand = $sousCommandes->sum(function ($sc) {
                    return ($sc->plat->prix_reduit ?? 0) * $sc->quantite_plat;
                });

                $commissionAdmin = ($totalMarchand * $commissionPercent) / 100;
                $partMarchand = $totalMarchand - $commissionAdmin;

                $marchand->increment('solde_marchand', $partMarchand);

                foreach ($sousCommandes as $sc) {
                    $sc->update([
                        'commission' => $commissionPercent
                    ]);
                }

                if ($admin) {
                    $admin->increment('solde', $commissionAdmin);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Callback traité avec succès'
        ], 200);
    }




}
