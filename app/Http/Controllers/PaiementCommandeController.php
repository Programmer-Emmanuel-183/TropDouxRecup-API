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

        $paniers = Panier::with(['plat.marchand'])
            ->where('id_client', $client->id)
            ->whereHas('plat', function ($q) use ($request) {
                $q->whereIn('id_marchand', $request->marchand_id);
            })
            ->get();

        if ($paniers->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun panier trouvé.'
            ], 404);
        }

        // 🔥 Vérification stock
        foreach ($paniers as $panier) {
            $plat = $panier->plat;

            if (!$plat || $plat->quantite_disponible < $panier->quantite) {
                return response()->json([
                    'success' => false,
                    'message' => "Stock insuffisant pour {$plat?->nom_plat}"
                ], 400);
            }
        }

        // =========================
        // CREATE COMMANDE
        // =========================
        $commande = new Commande();
        $commande->statut = 'pending';
        $commande->save();

        $totalPrix = 0;
        $sousCommandes = [];

        foreach ($paniers as $panier) {

            $plat = $panier->plat;

            // décrément stock
            $plat->decrement('quantite_disponible', $panier->quantite);

            // =========================
            // IMPORTANT FIX ICI
            // =========================
            $sous = new SousCommande();
            $sous->id_commande = $commande->id;
            $sous->id_client = $client->id;
            $sous->id_plat = $plat->id;
            $sous->id_marchand = $plat->id_marchand;
            $sous->quantite_plat = $panier->quantite;
            $sous->statut = 'pending';

            // 🔥 FIX BUG MYSQL (commission NOT NULL)
            $sous->commission = 0;

            $sous->code_commande = "TDR-" . strtoupper(substr($commande->id, 0, 6));

            $sous->code_qr = 'data:image/svg+xml;base64,' . base64_encode(
                QrCode::format('svg')->size(200)->generate($sous->code_commande)
            );

            $sous->save();

            $totalPrix += $plat->prix_reduit * $panier->quantite;

            $sousCommandes[] = $sous;
        }

        // =========================
        // PAIEMENT LOCAL
        // =========================
        $paiement = new PaiementCommande();
        $paiement->id_client = $client->id;
        $paiement->id_commande = $commande->id;
        $paiement->id_marchand = $paniers->first()->plat->id_marchand;
        $paiement->prix = $totalPrix;
        $paiement->statut = 'pending';
        $paiement->save();

        // =========================
        // GENIUSPAY PAYLOAD (INCHANGÉ)
        // =========================
        $payload = [
            "amount" => (int) $totalPrix,
            "currency" => "XOF",
            "description" => "Commande client",

            "success_url" => config('services.geniuspay.return_url'),
            "error_url" => config('services.geniuspay.return_url'),

            "customer" => [
                "name" => $client->nom_client,
                "email" => $client->email,
                "phone" => $client->telephone ?? "+22500000000",
                "country" => "CI"
            ],

            "metadata" => [
                "order_id" => $paiement->id,
                "id_client" => $client->id,
                "id_commande" => $commande->id
            ]
        ];

        $response = Http::withHeaders([
            'X-API-Key' => config('services.geniuspay.api_key'),
            'X-API-Secret' => config('services.geniuspay.api_secret'),
            'Content-Type' => 'application/json'
        ])->post(config('services.geniuspay.base_url') . '/payments', $payload);

        $result = $response->json();

        if ($response->failed() || !($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Paiement rejeté',
                'erreur' => $result['error']['message'] ?? $result['message'] ?? null
            ], 422);
        }

        // =========================
        // SAFE CHECK URL
        // =========================
        $checkoutUrl =
            $result['data']['checkout_url']
            ?? $result['data']['payment_url']
            ?? null;

        if (!$checkoutUrl) {
            return response()->json([
                'success' => false,
                'message' => 'URL de paiement introuvable'
            ], 422);
        }

        // =========================
        // SAVE RESPONSE GENIUSPAY
        // =========================
        $paiement->data = $result;
        $paiement->save();

        // =========================
        // RESPONSE FRONT (PROPRE)
        // =========================
        return response()->json([
            'success' => true,
            'data' => [
                'commande' => [
                    'id' => $commande->id,
                    'orderId' => "CMD-" . strtoupper(substr($commande->id, 0, 6)),
                    'status' => $commande->statut,
                    'createdAt' => $commande->created_at,
                    'totalPriceOrder' => $totalPrix,
                    'orderLength' => count($sousCommandes),
                    'dishes' => array_map(function ($sc) {
                        return [
                            'id' => $sc->id_plat,
                            'name' => $sc->plat->nom_plat,
                            'quantity' => $sc->quantite_plat,
                            'unit_price' => $sc->plat->prix_reduit,
                            'code_qr' => $sc->code_qr
                        ];
                    }, $sousCommandes)
                ],
                'redirectUrl' => $checkoutUrl
            ],
            'message' => 'Paiement initialisé avec succès'
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur interne',
            'erreur' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Vérifier le paiement d'une commande
     */
    public function verifier_paiement(Request $request, $depositId){
        $paiement = PaiementCommande::where('data->data->reference', $depositId)->first();

        if (!$paiement) {
            return response()->json([
                'success' => false,
                'message' => 'Paiement introuvable'
            ], 404);
        }

        if ($paiement->statut === 'completed') {
            $commande = Commande::with('sousCommandes.plat')->find($paiement->id_commande);

            return response()->json([
                'success' => true,
                'message' => 'Paiement déjà vérifié',
                'data' => $this->buildPayload($paiement, $commande)
            ]);
        }

        $response = Http::withHeaders([
            'X-API-Key' => config('services.geniuspay.api_key'),
            'X-API-Secret' => config('services.geniuspay.api_secret'),
        ])->get(config('services.geniuspay.base_url') . "/payments/{$depositId}");

        $result = $response->json();

        if ($response->failed() || !($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur GeniusPay'
            ], 422);
        }

        $status = $result['data']['status'] ?? 'pending';

        $paiement->update([
            'statut' => $status,
            'data' => $result
        ]);

        $commande = Commande::with('sousCommandes.plat')
            ->find($paiement->id_commande);

        if ($status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Paiement en cours',
                'data' => $this->buildPayload($paiement, $commande, $status)
            ]);
        }

        // =========================
        // COMPLETED LOGIC
        // =========================
        DB::transaction(function () use ($paiement, $commande) {
            $paiement->update(['statut' => 'completed']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Paiement vérifié avec succès',
            'data' => $this->buildPayload($paiement, $commande)
        ]);
    }

    private function buildPayload($paiement, $commande, $status = 'completed'){
        return [
            'id' => $paiement->id,
            'statut' => $status,
            'prix' => $paiement->prix,

            'commande' => [
                'id' => $commande->id,
                'orderId' => "CMD-" . strtoupper(substr($commande->id, 0, 6)),
                'status' => $commande->statut,
                'createdAt' => $commande->created_at,
                'totalPriceOrder' => $commande->sousCommandes->sum(function ($sc) {
                    return ($sc->plat->prix_reduit ?? 0) * $sc->quantite_plat;
                }),

                'dishes' => $commande->sousCommandes->map(function ($sc) {
                    return [
                        'id' => $sc->id_plat,
                        'name' => $sc->plat->nom_plat,
                        'quantity' => $sc->quantite_plat,
                        'unit_price' => $sc->plat->prix_reduit,
                        'code_qr' => $sc->code_qr
                    ];
                })->values()
            ]
        ];
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
