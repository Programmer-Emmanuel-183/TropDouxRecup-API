<?php

namespace App\Http\Controllers;

use App\Mail\NouvelAbonnementMarchandMail;
use App\Models\Abonnement;
use App\Models\Admin;
use App\Models\Facturation;
use App\Models\Marchand;
use App\Models\Notification;
use App\Models\PaiementAbonnement;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class PaiementAbonnementController extends Controller
{
    /**
     * =====================================================
     * INITIER PAIEMENT GENIUSPAY (CHECKOUT OFFICIEL)
     * =====================================================
     */
    public function initialiser_paiement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_abonnement' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {

            $abonnement = Abonnement::find($request->id_abonnement);

            if (!$abonnement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Abonnement introuvable'
                ], 404);
            }

            $marchand = Marchand::find($request->user()->id);

            if (!$marchand) {
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand introuvable'
                ], 404);
            }

            if ($marchand->fin_abonnement && $marchand->fin_abonnement >= Carbon::now()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Abonnement non épuisé.'
                ], 400);
            }

            // =========================
            // CREATE PAYMENT LOCAL
            // =========================
            $paiement = new PaiementAbonnement();
            $paiement->id_marchand = $marchand->id;
            $paiement->id_abonnement = $abonnement->id;
            $paiement->prix = $abonnement->montant;
            $paiement->statut = 'pending';
            $paiement->save();

            // =========================
            // GENIUSPAY PAYLOAD OFFICIEL
            // =========================
            $payload = [
                "amount" => (int) $abonnement->montant,
                "currency" => "XOF",
                "description" => "Abonnement marchand",
                "success_url" => config('services.geniuspay.return_url'),
                "error_url" => config('services.geniuspay.return_url'),

                "customer" => [
                    "name" => $marchand->nom_marchand,
                    "email" => $marchand->email_marchand,
                    "phone" => $marchand->tel_marchand,
                    "country" => "CI",
                ],

                "metadata" => [
                    "order_id" => $paiement->id,
                    "id_marchand" => $marchand->id,
                    "id_abonnement" => $abonnement->id
                ]
            ];

            // =========================
            // API CALL GENIUSPAY
            // =========================
            $response = Http::withHeaders([
                'X-API-Key' => config('services.geniuspay.api_key'),
                'X-API-Secret' => config('services.geniuspay.api_secret'),
                'Content-Type' => 'application/json'
            ])->post(
                config('services.geniuspay.base_url') . '/payments',
                $payload
            );

            $result = $response->json();

            if ($response->failed() || !($result['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paiement rejeté',
                    'erreur' => $result['error']['message'] ?? $result['message'] ?? null
                ], 422);
            }

            // IMPORTANT GENIUSPAY
            $checkoutUrl = $result['data']['checkout_url']
                ?? $result['data']['payment_url']
                ?? null;

            if (!$checkoutUrl) {
                return response()->json([
                    'success' => false,
                    'message' => 'URL de paiement introuvable'
                ], 422);
            }

            $paiement->data = $result;
            $paiement->save();

            // NOTIFICATION
            if ($marchand->device_token) {
                $notif = new Notification();
                $notif->type = 'abonnement';
                $notif->title = "Paiement en cours ⏳";
                $notif->body = "Votre abonnement {$abonnement->type_abonnement} est en cours de traitement.";
                $notif->role = 'marchand';
                $notif->id_user = $marchand->id;
                $notif->save();

                app(PushNotifController::class)->sendPush($notif);
            }

            // =========================
            // FRONT COMPATIBLE
            // =========================
            return response()->json([
                'success' => true,
                'data' => [
                    'marchand' => [
                        'id' => $marchand->id,
                        'nom' => $marchand->nom_marchand,
                        'email' => $marchand->email_marchand,
                        'telephone' => $marchand->tel_marchand
                    ],
                    'abonnement' => [
                        'id' => $abonnement->id,
                        'type_abonnement' => $abonnement->type_abonnement,
                        'montant' => $abonnement->montant,
                        'duree' => $abonnement->duree
                    ],
                    'paiement' => [
                        'id' => $paiement->id,
                        'prix' => $paiement->prix,
                        'id_marchand' => $marchand->id,
                        'id_abonnement' => $abonnement->id,
                    ],

                    // 🔥 NE CHANGE PAS FRONT
                    'redirectUrl' => $checkoutUrl
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * =====================================================
     * VERIFICATION (GENIUSPAY OFFICIEL)
     * =====================================================
     */
    public function verifier_paiement(Request $request, $depositId)
    {
        $paiement = PaiementAbonnement::where('data->data->reference', $depositId)->first();

        if (!$paiement) {
            return response()->json([
                'success' => false,
                'message' => 'Paiement introuvable'
            ], 404);
        }

        if ($paiement->statut === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Déjà complété'
            ], 409);
        }

        $response = Http::withHeaders([
            'X-API-Key' => config('services.geniuspay.api_key'),
            'X-API-Secret' => config('services.geniuspay.api_secret')
        ])->get(
            config('services.geniuspay.base_url') . '/payments/' . $depositId
        );

        $result = $response->json();

        if ($response->failed() || !($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $paiement
            ], 422);
        }

        $status = $result['data']['status'] ?? null;

        if ($status !== 'completed') {
            $paiement->update([
                'statut' => $status,
                'data' => $result
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Paiement en cours',
                'status' => $status
            ]);
        }

        // =========================
        // COMPLETED
        // =========================
        $paiement->update([
            'statut' => 'completed',
            'data' => $result
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Paiement validé'
        ]);
    }
}