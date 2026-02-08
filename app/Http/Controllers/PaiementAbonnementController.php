<?php

namespace App\Http\Controllers;

use App\Models\Abonnement;
use App\Models\Facturation;
use App\Models\Marchand;
use App\Models\Notification;
use App\Models\PaiementAbonnement;
use Doctrine\DBAL\Query\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class PaiementAbonnementController extends Controller
{
    public function initialiser_paiement(Request $request, $id_abonnement)
    {
        try {
            $abonnement = Abonnement::find($id_abonnement);
            if (!$abonnement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Abonnement introuvable'
                ], 404);
            }

            $user = $request->user();
            $marchand = Marchand::find($user->id);

            if (!$marchand) {
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand introuvable'
                ], 404);
            }

            if($marchand->fin_abonnement >= Carbon::now()){
                return response()->json([
                    'success' => false,
                    'message' => 'Abonnement non épuisé.'
                ],400);
            }

            $paiement = new PaiementAbonnement();
            $paiement->id_marchand = $marchand->id;
            $paiement->id_abonnement = $abonnement->id;
            $paiement->prix = $abonnement->montant;
            $paiement->save();

            $payload = [
                "depositId" => $paiement->id,
                "returnUrl" => config('services.pawapay.return_url'),
                "customerMessage" => "Paiement abonnement",
                "amountDetails" => [
                    "amount" => (string) $abonnement->montant,
                    "currency" => "XOF"
                ],
                "language" => "FR",
                "country" => "CIV",
                "reason" => "Abonnement marchand",
                "metadata" => [
                    ["orderId" => $paiement->id],
                    ["nom" => $marchand->nom_marchand],
                    ["email" => $marchand->email_marchand],
                    ["id_abonnement" => $abonnement->id],
                    ["id_marchand" => $marchand->id],
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

            if($marchand->device_token !== null){
                $notification_marchand = new Notification();
                $notification_marchand->type = 'abonnement';
                $notification_marchand->title = "Paiement en cours ⏳";
                $notification_marchand->body = "Votre demande d’abonnement {$abonnement->type_abonnement} est en cours de traitement. Veuillez patientez le temps que votre compte soit mis à jour.";
                $notification_marchand->role = 'marchand';
                $notification_marchand->id_user = $marchand->id;
                $notification_marchand->save();
                app(PushNotifController::class)->sendPush($notification_marchand);
            }


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
                        'id_marchand' => $paiement->id_marchand,
                        'id_abonnement' => $paiement->id_abonnement,
                    ],
                    'redirectUrl' => $result['redirectUrl']
                ]
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’initialisation du paiement',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }

    public function verifier_paiement(Request $request, $depositId){
        $paiement = PaiementAbonnement::find($depositId);

        if (!$paiement) {
            return response()->json([
                'success' => false,
                'message' => 'Paiement introuvable'
            ], 404);
        }

        // 🔹 2. Déjà validé
        if ($paiement->statut === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Ce paiement a déjà été vérifié et complété'
            ], 409);
        }

        // 🔹 3. Appel Pawapay
        $response = Http::withToken(config('services.pawapay.api_key'))
            ->get("https://api.sandbox.pawapay.io/v2/deposits/{$depositId}");

        $result = $response->json();

        // 🔴 Erreur API Pawapay
        if ($response->failed() || ($result['status'] ?? null) === 'REJECTED') {
            return response()->json([
                'success' => false,
                'message' => 'Erreur Pawapay',
                'erreur' => $result['failureReason'] ?? 'Erreur inconnue'
            ], 422);
        }

        // 🔴 Paiement non trouvé
        if (($result['status'] ?? null) === 'NOT_FOUND') {
            return response()->json([
                'success' => false,
                'message' => 'Paiement non trouvé chez Pawapay'
            ], 404);
        }

        // 🔹 4. Statut Pawapay
        $pawapayStatus = $result['data']['status'] ?? null;


         if ($pawapayStatus === 'FAILED') {
            $paiement->update([
                'statut' => 'failed',
                'data' => $result
            ]);

            return response()->json([
                'success' => false,
                'data' => [
                    'id' => $paiement->id,
                    'statut' => $paiement->statut,
                    'prix' => $paiement->prix,
                    'data' => $paiement->data,
                    'marchand' => [
                        'id' => $paiement->marchand->id,
                        'nom' => $paiement->marchand->nom_marchand,
                        'email' => $paiement->marchand->email_marchand,
                        'telephone' => $paiement->marchand->tel_marchand,
                        'abonnement' => [
                            'id' => $paiement->marchand->abonnement->id,
                            'type_abonnement' => $paiement->marchand->abonnement->type_abonnement,
                            'fin_abonnement' => $paiement->marchand->fin_abonnement,
                        ],
                    ],
                    'abonnement' => [
                        'id' => $paiement->marchand->abonnement->id,
                        'type_abonnement' => $paiement->marchand->abonnement->type_abonnement,
                        'montant' => $paiement->marchand->abonnement->montant,
                        'duree' => $paiement->marchand->abonnement->duree
                    ],
                ],
                'message' => 'Paiement échoué'
            ], 200);
        }

        if ($pawapayStatus !== 'COMPLETED') {
            $paiement->update([
                'statut' => 'pending',
                'data' => $result
            ]);
            return response()->json([
                'success' => false,
                'data' => [
                    'id' => $paiement->id,
                    'statut' => $paiement->statut,
                    'prix' => $paiement->prix,
                    'data' => $paiement->data,
                    'marchand' => [
                        'id' => $paiement->marchand->id,
                        'nom' => $paiement->marchand->nom_marchand,
                        'email' => $paiement->marchand->email_marchand,
                        'telephone' => $paiement->marchand->tel_marchand,
                        'abonnement' => [
                            'id' => $paiement->marchand->abonnement->id,
                            'type_abonnement' => $paiement->marchand->abonnement->type_abonnement,
                            'fin_abonnement' => $paiement->marchand->fin_abonnement,
                        ],
                    ],
                    'abonnement' => [
                        'id' => $paiement->marchand->abonnement->id,
                        'type_abonnement' => $paiement->marchand->abonnement->type_abonnement,
                        'montant' => $paiement->marchand->abonnement->montant,
                        'duree' => $paiement->marchand->abonnement->duree
                    ],
                ],
                'message' => 'Paiement non finalisé',
                'status' => $pawapayStatus
            ], 200);
        }

        // 🔹 4.1 Vérification du montant
        $amountPawapay = $result['data']['amount'] ?? null;

        if (!$amountPawapay) {
            return response()->json([
                'success' => false,
                'message' => 'Montant du paiement introuvable chez Pawapay'
            ], 422);
        }

        // Cast en int (ex: "20000.00" → 20000)
        $amountPawapayInt = (int) round((float) $amountPawapay);

        if ($amountPawapayInt !== (int) $paiement->prix) {
            return response()->json([
                'success' => false,
                'message' => 'Incohérence du montant du paiement',
                'details' => [
                    'montant_attendu' => (int) $paiement->prix,
                    'montant_recu' => $amountPawapayInt
                ]
            ], 422);
        }


        // ✅ 5. Paiement COMPLETED
        $paiement->update([
            'statut' => 'completed',
            'data' => $result
        ]);

        $marchand = Marchand::find($paiement->id_marchand);
        if(!$marchand){
            return response()->json([
                'success' => false,
                'message' => 'Marchand introuvable'
            ],404);
        }

        $abonnement = Abonnement::find($paiement->id_abonnement);
        if(!$abonnement){
            return response()->json([
                'success' => false,
                'message' => 'Abonnement introuvable'
            ],404);
        }
        
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

            case 'illimite':
                $duree = null; // pas de fin d’abonnement
                break;

            default:
                $duree = null;
        }

        $marchand->id_abonnement = $abonnement->id;
        $marchand->fin_abonnement = $duree;
        $marchand->save();

        $facturation = new Facturation();
        $facturation->nom_abonnement = $paiement->abonnement->type_abonnement;
        $facturation->montant = $paiement->prix;
        $facturation->id_user = $paiement->marchand->id;
        $facturation->save();

        if ($marchand->device_token !== null) {

            $notification_marchand = new Notification();
            $notification_marchand->type = 'abonnement';
            $notification_marchand->title = 'Abonnement activé 🎉';
            $notification_marchand->body = "Votre abonnement {$abonnement->type_abonnement} a été activé avec succès.";

            if ($marchand->fin_abonnement) {
                $notification_marchand->body .= " Il est valide jusqu’au " . $marchand->fin_abonnement->format('d/m/Y') . ".";
            } else {
                $notification_marchand->body .= " Il est valide sans date d’expiration.";
            }

            $notification_marchand->role = 'marchand';
            $notification_marchand->id_user = $marchand->id;
            $notification_marchand->save();

            app(PushNotifController::class)->sendPush($notification_marchand);
        }


        return response()->json([
            'success' => true,
            'data' => [
                'id' => $paiement->id,
                'statut' => $paiement->statut,
                'prix' => $paiement->prix,
                'data' => $paiement->data,
                'marchand' => [
                    'id' => $marchand->id,
                    'nom' => $marchand->nom_marchand,
                    'email' => $marchand->email_marchand,
                    'telephone' => $marchand->tel_marchand,
                    'abonnement' => [
                        'id' => $marchand->abonnement->id,
                        'type_abonnement' => $marchand->abonnement->type_abonnement,
                        'fin_abonnement' => $marchand->fin_abonnement,
                    ],
                ],
                'abonnement' => [
                    'id' => $abonnement->id,
                    'type_abonnement' => $abonnement->type_abonnement,
                    'montant' => $abonnement->montant,
                    'duree' => $abonnement->duree
                ],
            ],
            'message' => 'Paiement complété avec succès',
        ], 200);
    }


    public function callback_pawapay(Request $request){
        $depositId = $request->input('depositId');

        if (!$depositId) {
            return response()->json([
                'success' => false,
                'message' => 'depositId manquant'
            ], 400);
        }

        $paiement = PaiementAbonnement::find($depositId);

        if (!$paiement) {
            return response()->json([
                'success' => false,
                'message' => 'Paiement introuvable'
            ], 404);
        }
        if ($paiement->statut === 'completed') {
            return response()->json([
                'success' => true,
                'message' => 'Paiement déjà traité'
            ], 200);
        }

        $pawapayStatus = $request->input('status');
        $amountPawapay = $request->input('amount');

       if ($pawapayStatus === 'FAILED') {
            $paiement->update([
                'statut' => 'failed',
                'data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'data' => [
                    'id' => $paiement->id,
                    'statut' => $paiement->statut,
                    'prix' => $paiement->prix,
                    'data' => $paiement->data,
                    'marchand' => [
                        'id' => $paiement->marchand->id,
                        'nom' => $paiement->marchand->nom_marchand,
                        'email' => $paiement->marchand->email_marchand,
                        'telephone' => $paiement->marchand->tel_marchand,
                        'abonnement' => [
                            'id' => $paiement->marchand->abonnement->id,
                            'type_abonnement' => $paiement->marchand->abonnement->type_abonnement,
                            'fin_abonnement' => $paiement->marchand->fin_abonnement,
                        ],
                    ],
                    'abonnement' => [
                        'id' => $paiement->marchand->abonnement->id,
                        'type_abonnement' => $paiement->marchand->abonnement->type_abonnement,
                        'montant' => $paiement->marchand->abonnement->montant,
                        'duree' => $paiement->marchand->abonnement->duree
                    ],
                ],
                'message' => 'Paiement échoué'
            ], 200);
        }

        if ($pawapayStatus !== 'COMPLETED') {
            $paiement->update([
                'statut' => 'pending',
                'data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'data' => [
                    'id' => $paiement->id,
                    'statut' => $paiement->statut,
                    'prix' => $paiement->prix,
                    'data' => $paiement->data,
                    'marchand' => [
                        'id' => $paiement->marchand->id,
                        'nom' => $paiement->marchand->nom_marchand,
                        'email' => $paiement->marchand->email_marchand,
                        'telephone' => $paiement->marchand->tel_marchand,
                        'abonnement' => [
                            'id' => $paiement->marchand->abonnement->id,
                            'type_abonnement' => $paiement->marchand->abonnement->type_abonnement,
                            'fin_abonnement' => $paiement->marchand->fin_abonnement,
                        ],
                    ],
                    'abonnement' => [
                        'id' => $paiement->marchand->abonnement->id,
                        'type_abonnement' => $paiement->marchand->abonnement->type_abonnement,
                        'montant' => $paiement->marchand->abonnement->montant,
                        'duree' => $paiement->marchand->abonnement->duree
                    ],
                ],
                'message' => 'Paiement non finalisé',
                'status' => $pawapayStatus
            ], 200);
        }

        if (!$amountPawapay) {
            return response()->json([
                'success' => false,
                'message' => 'Montant manquant dans le callback'
            ], 422);
        }

        $amountPawapayInt = (int) round((float) $amountPawapay);

        if ($amountPawapayInt !== (int) $paiement->prix) {
            return response()->json([
                'success' => false,
                'message' => 'Incohérence du montant',
                'details' => [
                    'montant_attendu' => (int) $paiement->prix,
                    'montant_recu' => $amountPawapayInt
                ]
            ], 422);
        }

        $paiement->update([
            'statut' => 'completed',
            'data' => $request->all()
        ]);

        $marchand = Marchand::find($paiement->id_marchand);
        $abonnement = Abonnement::find($paiement->id_abonnement);

        if (!$marchand || !$abonnement) {
            return response()->json([
                'success' => false,
                'message' => 'Marchand ou abonnement introuvable'
            ], 404);
        }

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
            case 'illimite':
            default:
                $duree = null;
        }

        $marchand->update([
            'id_abonnement' => $abonnement->id,
            'fin_abonnement' => $duree
        ]);

        $facturation = new Facturation();
        $facturation->nom_abonnement = $paiement->abonnement->type_abonnement;
        $facturation->montant = $paiement->prix;
        $facturation->id_user = $paiement->marchand->id;
        $facturation->save();

        if ($marchand->device_token !== null) {

            $notification_marchand = new Notification();
            $notification_marchand->type = 'abonnement';
            $notification_marchand->title = 'Abonnement activé 🎉';
            $notification_marchand->body = "Votre abonnement {$abonnement->type_abonnement} a été activé avec succès.";

            if ($marchand->fin_abonnement) {
                $notification_marchand->body .= " Il est valide jusqu’au " . $marchand->fin_abonnement->format('d/m/Y') . ".";
            } else {
                $notification_marchand->body .= " Il est valide sans date d’expiration.";
            }

            $notification_marchand->role = 'marchand';
            $notification_marchand->id_user = $marchand->id;
            $notification_marchand->save();

            app(PushNotifController::class)->sendPush($notification_marchand);
        }


        return response()->json([
            'success' => true,
            'message' => 'Callback traité avec succès'
        ], 200);
    }



}
