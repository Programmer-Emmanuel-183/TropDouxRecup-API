<?php

namespace App\Http\Controllers;

use App\Models\RetraitAdmin;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RetraitAdminController extends Controller
{
    /**
     * Vérifier que l'utilisateur est admin role=2
     */
    private function checkRole(Request $request)
    {
        $admin = $request->user();
        if (!$admin || $admin->role != 2) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé'
            ], 403);
        }
        return $admin;
    }

    /**
     * Initialiser un retrait admin
     */
    public function initialiser_retrait(Request $request)
    {
        $admin = $this->checkRole($request);
        if ($admin instanceof \Illuminate\Http\JsonResponse) return $admin;

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

        // Vérifier le solde
        if ($admin->solde < $request->montant) {
            return response()->json([
                'success' => false,
                'message' => 'Solde insuffisant'
            ], 400);
        }

        try {
            return DB::transaction(function () use ($request, $admin) {

                // 🔥 Déduire immédiatement le solde de l’admin
                $admin->decrement('solde', $request->montant);

                // 🔥 Création retrait
                $retrait = RetraitAdmin::create([
                    'id' => Str::uuid(),
                    'prix' => $request->montant,
                    'statut' => 'pending',
                    'id_admin' => $admin->id,
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
                    // 🔥 remboursement immédiat si rejet
                    $admin->increment('solde', $request->montant);

                    return response()->json([
                        'success' => false,
                        'message' => 'Retrait rejeté',
                        'erreur' => $result
                    ], 422);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Retrait admin initialisé',
                    'data' => $retrait->makeHidden(['data'])
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

    /**
     * Vérifier un retrait admin
     */
    public function verifier_retrait(Request $request, $payoutId)
    {

        $retrait = RetraitAdmin::find($payoutId);

        $admin = Admin::find($retrait->id_admin);

        if (!$retrait) {
            return response()->json([
                'success' => false,
                'message' => 'Retrait introuvable'
            ], 404);
        }

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

            DB::transaction(function () use ($retrait, $data, $admin) {
                $status = $data['status'];

                if ($status === 'COMPLETED') {
                    $retrait->update([
                        'statut' => 'completed',
                        'data' => $data
                    ]);
                } elseif ($status === 'FAILED') {
                    // 🔥 remboursement si échec
                    $admin->increment('solde', $retrait->prix);
                    $retrait->update([
                        'statut' => 'failed',
                        'data' => $data
                    ]);
                } else {
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

    /**
     * Callback Pawapay pour retrait admin
     */
    public function callback_retrait(Request $request)
    {
        $payoutId = $request->input('payoutId');
        if (!$payoutId) {
            return response()->json(['success' => false, 'message' => 'payoutId manquant'], 400);
        }

        $retrait = RetraitAdmin::find($payoutId);
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

        $admin = Admin::find($retrait->id_admin);

        DB::transaction(function () use ($retrait, $request, $status, $admin) {

            if ($status === 'FAILED') {
                // 🔥 remboursement si échec
                $admin->increment('solde', $retrait->prix);

                $retrait->update([
                    'statut' => 'failed',
                    'data' => $request->all()
                ]);
                return;
            }

            if ($status === 'COMPLETED') {
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
            'message' => 'Callback admin traité'
        ], 200);
    }

    public function historiques_retrait(Request $request){
        $admin = $this->checkRole($request);
        if ($admin instanceof \Illuminate\Http\JsonResponse) return $admin;

        $retrails = RetraitAdmin::where('id_admin', $admin->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->makeHidden(['data']); // masquer le payload Pawapay

        return response()->json([
            'success' => true,
            'message' => 'Historique des retraits',
            'data' => $retrails
        ]);
    }
}