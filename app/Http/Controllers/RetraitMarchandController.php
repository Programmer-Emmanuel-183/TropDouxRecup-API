<?php

namespace App\Http\Controllers;

use App\Models\Marchand;
use App\Models\RetraitMarchand;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RetraitMarchandController extends Controller
{

    public function operateurs_disponible(Request $request){
        try {

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.pawapay.api_key'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->get('https://api.sandbox.pawapay.io/v2/active-conf', [
                'country' => 'CIV',
                'operationType' => 'PAYOUT'
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'data' => [],
                    'message' => 'Erreur lors de la récupération des opérateurs.'
                ], 500);
            }

            $body = $response->json();

            $operateurs = [];

            if (!empty($body['countries'][0]['providers'])) {

                foreach ($body['countries'][0]['providers'] as $provider) {

                    $operateurs[] = [
                        'id' => $provider['provider'], // MTN_MOMO_CIV / ORANGE_CIV
                        'nom' => $provider['displayName'], // MTN / Orange
                        'image' => $provider['logo']
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => $operateurs,
                'message' => 'Liste des opérateurs disponible.'
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage des opérateurs disponibles',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }

    public function initialiser_retrait(Request $request){
        $validator = Validator::make($request->all(), [
            'id_operateur' => 'required|string',
            'phone' => 'required|string',
            'montant' => 'required|integer|min:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $marchand = $request->user();

        if ($marchand->solde_marchand < $request->montant) {
            return response()->json([
                'success' => false,
                'message' => 'Solde insuffisant'
            ], 400);
        }

        try {

            return DB::transaction(function () use ($request, $marchand) {

                // 🔥 Création retrait
                $retrait = RetraitMarchand::create([
                    'id' => Str::uuid(),
                    'prix' => $request->montant,
                    'statut' => 'pending',
                    'id_marchand' => $marchand->id,
                ]);

                // 🔥 Déduire du solde
                $marchand->decrement('solde_marchand', $request->montant);

                // 🔥 Créer transaction (pending)
                Transaction::create([
                    'id' => Str::uuid(),
                    'amount' => $request->montant,
                    'type' => 'debit',
                    'libelle' => 'Retrait vers Mobile Money',
                    'id_user' => $marchand->id,
                    'is_pending' => true
                ]);

                // 🔥 Payload Pawapay
                $payload = [
                    "payoutId" => $retrait->id,
                    "amount" => (string) $request->montant,
                    "currency" => "XOF",
                    "recipient" => [
                        "type" => "MMO",
                        "accountDetails" => [
                            "phoneNumber" => $request->phone,
                            "provider" => $request->id_operateur
                        ]
                    ]
                ];

                $response = Http::withToken(config('services.pawapay.api_key'))
                    ->post('https://api.sandbox.pawapay.io/v2/payouts', $payload);

                $result = $response->json();

                if ($response->failed() || ($result['status'] ?? null) !== 'ACCEPTED') {

                    // ❌ Si rejet immédiat → remboursement
                    $marchand->increment('solde_marchand', $request->montant);

                    return response()->json([
                        'success' => false,
                        'message' => 'Retrait rejeté',
                        'erreur' => $result
                    ], 422);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Retrait initialisé',
                    'data' => $retrait
                ]);

            });

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur interne',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }


    public function verifier_retrait(Request $request, $payoutId){
        $retrait = RetraitMarchand::find($payoutId);

        if (!$retrait) {
            return response()->json([
                'success' => false,
                'message' => 'Retrait introuvable'
            ], 404);
        }

        // Si déjà finalisé
        if (in_array($retrait->statut, ['completed', 'failed'])) {
            return response()->json([
                'success' => true,
                'message' => 'Retrait déjà finalisé'
            ]);
        }

        try {
            $response = Http::withToken(config('services.pawapay.api_key'))
                ->get("https://api.sandbox.pawapay.io/v2/payouts/{$payoutId}");

            $result = $response->json();
            $data = $result['data'] ?? null;

            if (($result['status'] ?? null) === 'NOT_FOUND') {
                return response()->json([
                    'success' => false,
                    'message' => 'Retrait non trouvé chez Pawapay'
                ], 404);
            }

            if (!$data) {
                return response()->json([
                    'success' => false,
                    'message' => 'Réponse invalide'
                ], 422);
            }

            DB::transaction(function () use ($retrait, $data) {
                $marchand = Marchand::find($retrait->id_marchand);

                $transaction = Transaction::where('id_user', $marchand->id)
                    ->where('amount', $retrait->prix)
                    ->where('is_pending', true)
                    ->latest()
                    ->first();

                $status = $data['status'];

                if ($status === 'COMPLETED') {
                    if ($transaction) {
                        $transaction->update(['is_pending' => false]);
                    }
                    $retrait->update([
                        'statut' => 'completed',
                        'data' => $data
                    ]);
                } elseif ($status === 'FAILED') {
                    // 🔥 Remboursement
                    $marchand->increment('solde_marchand', $retrait->prix);
                    if ($transaction) {
                        $transaction->update(['is_pending' => false]);
                    }
                    $retrait->update([
                        'statut' => 'failed',
                        'data' => $data
                    ]);
                } else {
                    // ENQUEUED / PROCESSING
                    $retrait->update([
                        'statut' => strtolower($status),
                        'data' => $data
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Statut synchronisé',
                'data' => $retrait->makeHidden(['data'])
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur vérification',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }


    public function callback_retrait(Request $request){
        $payoutId = $request->input('payoutId');

        if (!$payoutId) {
            return response()->json(['success' => false, 'message' => 'payoutId manquant'], 400);
        }

        $retrait = RetraitMarchand::find($payoutId);

        if (!$retrait) {
            return response()->json(['success' => false, 'message' => 'Retrait introuvable'], 404);
        }

        if ($retrait->statut === 'completed') {
            return response()->json(['success' => true]);
        }

        $status = $request->input('status');
        $amount = (int) round((float) $request->input('amount'));

        if ($amount !== (int) $retrait->prix) {
            return response()->json([
                'success' => false,
                'message' => 'Montant incohérent'
            ], 422);
        }

        DB::transaction(function () use ($retrait, $request, $status) {

            $marchand = Marchand::find($retrait->id_marchand);

            $transaction = Transaction::where('id_user', $marchand->id)
                ->where('amount', $retrait->prix)
                ->where('is_pending', true)
                ->latest()
                ->first();

            if ($status === 'FAILED') {

                // 🔥 Remboursement
                $marchand->increment('solde_marchand', $retrait->prix);

                if ($transaction) {
                    $transaction->update(['is_pending' => false]);
                }

                $retrait->update([
                    'statut' => 'failed',
                    'data' => $request->all()
                ]);

                return;
            }

            if ($status === 'COMPLETED') {

                if ($transaction) {
                    $transaction->update(['is_pending' => false]);
                }

                $retrait->update([
                    'statut' => 'completed',
                    'data' => $request->all()
                ]);

                return;
            }

            // PROCESSING / ENQUEUED
            $retrait->update([
                'statut' => strtolower($status),
                'data' => $request->all()
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Callback traité'
        ],200);
    }


}
