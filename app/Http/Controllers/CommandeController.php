<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Commande;
use App\Models\Commission;
use App\Models\Notification;
use App\Models\Panier;
use App\Models\Plat;
use App\Models\SousCommande;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class CommandeController extends Controller
{
    public function passer_commande(Request $request){
        $validator = Validator::make($request->all(), [
            'id_item' => 'required|array',
            'id_item.*' => 'exists:paniers,id'
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
                ->whereIn('id', $request->id_item)
                ->get();

            if ($paniers->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun panier trouvé pour ce client.'
                ], 404);
            }

            // 🔒 Vérification plats supprimés
            foreach ($paniers as $panier) {
                $plat = Plat::whereNull('deleted_at')
                    ->where('id', $panier->id_plat)
                    ->first();

                if (!$plat) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Un ou plusieurs plats ne sont plus disponibles'
                    ], 400);
                }
            }

            // 🔒 Vérification stock
            foreach ($paniers as $panier) {
                if ($panier->plat->quantite_disponible < $panier->quantite) {
                    return response()->json([
                        'success' => false,
                        'message' => "Stock insuffisant pour le plat : {$panier->plat->nom_plat}"
                    ], 400);
                }
            }

            $commission = Commission::first();

            $commande = new Commande();
            $commande->statut = 'pending';
            $commande->save();

            // Pour regrouper les commandes par marchand (notifications)
            $marchandCommandes = [];

            foreach ($paniers as $panier) {

                $plat = $panier->plat;

                // Mise à jour stock
                $plat->quantite_disponible -= $panier->quantite;
                $plat->save();

                $sous = new SousCommande();
                $sous->commission = $commission->pourcentage ?? 0;
                $sous->id_commande = $commande->id;
                $sous->id_client = $client->id;
                $sous->id_plat = $panier->id_plat;
                $sous->quantite_plat = $panier->quantite;
                $sous->id_marchand = $plat->id_marchand;
                $sous->statut = 'pending';
                $sous->code_commande = "TDR-" . strtoupper(substr($commande->id, 0, 6));

                $svg = QrCode::format('svg')->size(200)->generate($sous->code_commande);
                $sous->code_qr = 'data:image/svg+xml;base64,' . base64_encode($svg);
                $sous->save();

                // 📦 Regroupement pour notification marchand
                $marchandCommandes[$plat->id_marchand][] = $sous;
            }

            // Nettoyage paniers
            Panier::where('id_client', $client->id)
                ->whereIn('id', $request->id_item)
                ->delete();

            $sousCommandes = SousCommande::with(['plat.marchand'])
                ->where('id_commande', $commande->id)
                ->get();

            $orderId = $sousCommandes->first()->code_commande;

            $dishes = [];
            $totalPrice = 0;
            $totalQuantity = 0;

            $admin = Admin::where('role', 2)->first();

            foreach ($sousCommandes as $sc) {

                $plat = $sc->plat;
                $marchand = $plat->marchand;

                $montantTotal = $plat->prix_reduit * $sc->quantite_plat;
                $commissionAdmin = ($montantTotal * ($commission->pourcentage ?? 0)) / 100;
                $partMarchand = $montantTotal - $commissionAdmin;

                $marchand->solde_marchand += $partMarchand;
                $marchand->save();

                $admin->solde += $commissionAdmin;

                $dishes[] = [
                    'id' => $sc->id_plat,
                    'name' => $plat->nom_plat,
                    'quantity' => $sc->quantite_plat,
                    'unit_price' => $plat->prix_reduit,
                    'code_qr' => $sc->code_qr
                ];

                $totalPrice += $montantTotal;
                $totalQuantity += $sc->quantite_plat;
            }

            $admin->save();

            if($client->device_token !== null){
                $notification_client = new Notification();
                $notification_client->type = 'commande';
                $notification_client->title = 'Commande effectuée 🎉';
                $notification_client->body = "Votre commande a été envoyée avec succès.";
                $notification_client->role = 'client';
                $notification_client->id_user = $client->id;
                $notification_client->save();
                app(PushNotifController::class)->sendPush($notification_client);
            }
            

            $notifications = [];

            foreach ($marchandCommandes as $idMarchand => $commandes) {

                $marchand = $commandes[0]->plat->marchand;
                $nbPlats = collect($commandes)->sum('quantite_plat');
                if($marchand->device_token !== null){
                    $notification = Notification::create([
                        'type' => 'commande',
                        'title' => 'Nouvelle commande 📦',
                        'body' => "Vous avez reçu une nouvelle commande de {$client->nom_client} ({$nbPlats} plat(s)).",
                        'role' => 'marchand',
                        'id_user' => $marchand->id,
                    ]);
                }

                $notifications[] = $notification;
            }

            // 🔥 ENVOI OPTIMISÉ
            $pushService = app(PushNotifController::class);

            if (count($notifications) === 1) {
                $pushService->sendPush($notifications[0]);
            } else {
                $pushService->sendPushBatch($notifications);
            }


            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $commande->id,
                    'orderId' => $orderId,
                    'customerName' => $client->nom_client,
                    'status' => $commande->statut,
                    'createdAt' => $commande->created_at,
                    'commission' => $commission->pourcentage ?? 0,
                    'totalPriceOrder' => $totalPrice,
                    'orderLength' => $totalQuantity,
                    'completedAt' => null,
                    'dishes' => $dishes
                ],
                'message' => 'Commande effectuée avec succès'
            ],200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne : ' . $e->getMessage()
            ], 500);
        }
    }


    public function commandes_client(Request $request){
        try {
            $client = $request->user();

            $sousCommandes = SousCommande::with('plat')
                ->where('id_client', $client->id)
                ->orderBy('created_at', 'desc')
                ->get();

            if ($sousCommandes->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune commande trouvée.'
                ], 404);
            }

            $grouped = $sousCommandes->groupBy('id_commande');
            $result = [];

            foreach ($grouped as $commandeId => $items) {

                $commande = Commande::find($commandeId);
                $orderId = $items->first()->code_commande;
                $commission = $items->first()->commission ?? 0;

                $dishes = [];
                $totalPrice = 0;
                $totalQuantity = 0;

                foreach ($items as $s) {
                    $dishes[] = [
                        'id' => $s->id_plat,
                        'name' => $s->plat->nom_plat,
                        'quantity' => $s->quantite_plat,
                        'unit_price' => $s->plat->prix_reduit,
                        'code_qr' => $s->code_qr
                    ];

                    $totalPrice += $s->plat->prix_reduit * $s->quantite_plat;
                    $totalQuantity += $s->quantite_plat;
                }

                $allRecovered = $items->every(fn($i) => $i->date_de_recuperation !== null);

                $completedAt = $allRecovered
                    ? $items->max('date_de_recuperation')->toISOString()
                    : null;

                $result[] = [
                    'id' => $commande->id,
                    'orderId' => $orderId,
                    'customerName' => $client->nom_client,
                    'status' => $commande->statut,
                    'createdAt' => $commande->created_at,
                    'commission' => $commission,
                    'totalPriceOrder' => $totalPrice,
                    'orderLength' => $totalQuantity,
                    'completedAt' => $completedAt,
                    'dishes' => $dishes
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Commandes du client affiché avec succès'
            ],200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne : ' . $e->getMessage()
            ], 500);
        }
    }


    public function commandes_marchand(Request $request){
        try {
            $marchand = $request->user();
            $limit = $request->limit;
            $statut = $request->query('statut'); // completed | pending

            $query = SousCommande::with(['plat', 'client'])
                ->where('id_marchand', $marchand->id)
                ->orderBy('created_at', 'desc');

            // 🔹 Limite
            if ($limit) {
                $query->limit($limit);
            }

            $sousCommandes = $query->get();

            if ($sousCommandes->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucune commande trouvée.'
                ], 200);
            }

            // 🔹 Grouper par commande
            $grouped = $sousCommandes->groupBy('id_commande');
            $result = [];

            foreach ($grouped as $commandeId => $items) {

                // 🔹 Statut calculé
                $allRecovered = $items->every(fn ($i) => $i->date_de_recuperation !== null);

                // 🔹 Filtrage par statut (SANS changer la réponse)
                if ($statut === 'completed' && !$allRecovered) {
                    continue;
                }

                if ($statut === 'pending' && $allRecovered) {
                    continue;
                }

                $commande = Commande::find($commandeId);

                $orderId = $items->first()->code_commande;
                $commission = $items->first()->commission ?? 0;
                $clientName = $items->first()->client->nom_client ?? '';

                $dishes = [];
                $totalPrice = 0;
                $totalQuantity = 0;

                foreach ($items as $s) {
                    $dishes[] = [
                        'id' => $s->id_plat,
                        'name' => $s->plat->nom_plat,
                        'quantity' => $s->quantite_plat,
                        'unit_price' => $s->plat->prix_reduit,
                    ];

                    $totalPrice += $s->plat->prix_reduit * $s->quantite_plat;
                    $totalQuantity += $s->quantite_plat;
                }

                $completedAt = $allRecovered
                    ? $items->max('date_de_recuperation')->toISOString()
                    : null;

                $result[] = [
                    'id' => $commande->id,
                    'orderId' => $orderId,
                    'customerName' => $clientName,
                    'status' => $commande->statut,
                    'createdAt' => $commande->created_at,
                    'commission' => $commission,
                    'totalPriceOrder' => $totalPrice,
                    'orderLength' => $totalQuantity,
                    'completedAt' => $completedAt,
                    'dishes' => $dishes
                ];
            }

            return response()->json([
                'success' => true,
                'data' => array_values($result), // reindex propre
                'external_data' => [
                    'total_commandes' => count($result),
                    'total_recuperees' => collect($sousCommandes)
                        ->filter(fn ($c) => $c->date_de_recuperation !== null)
                        ->count(),
                    'total_en_attente' => collect($sousCommandes)
                        ->filter(fn ($c) => $c->date_de_recuperation === null)
                        ->count(),
                ],
                'message' => 'Commandes du marchand affiché avec succès'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne : ' . $e->getMessage()
            ], 500);
        }
    }


    public function marquer_comme_recupere(Request $request){
        $validator = Validator::make($request->query(), [
            'code_commande' => 'required|exists:sous_commandes,code_commande',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $marchand = $request->user();
            $dateNow = now();
            $codeCommande = $request->query('code_commande');

            $sousCommandes = SousCommande::with(['plat', 'client'])
                ->where('code_commande', $codeCommande)
                ->where('id_marchand', $marchand->id)
                ->get();

            if ($sousCommandes->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => "Aucune sous-commande trouvée pour ce marchand."
                ], 404);
            }

            foreach ($sousCommandes as $s) {
                $s->statut = 'completed';
                $s->date_de_recuperation = $dateNow;
                $s->save();
            }

            $commandeId = $sousCommandes->first()->id_commande;
            $commande = Commande::find($commandeId);

            $allRecovered = SousCommande::where('id_commande', $commandeId)
                ->whereNull('date_de_recuperation')
                ->doesntExist();

            if ($allRecovered) {
                $commande->statut = 'completed';
                $commande->save();
            }

            $dishes = [];
            $totalPrice = 0;
            $totalQuantity = 0;

            foreach ($sousCommandes as $s) {
                $dishes[] = [
                    'id' => $s->id_plat,
                    'name' => $s->plat?->nom_plat ?? 'Plat supprimé',
                    'quantity' => $s->quantite_plat,
                    'unit_price' => $s->plat?->prix_reduit ?? 0,
                    'code_qr' => $s->code_qr,
                ];

                $totalPrice += ($s->plat?->prix_reduit ?? 0) * $s->quantite_plat;
                $totalQuantity += $s->quantite_plat;
            }

            $client = $sousCommandes->first()->client;
            if(!$client){
                return response()->json([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ],404);
            }

            if($client->device_token !== null){
                $notification_client = new Notification();
                $notification_client->type = 'commande_recuperation';
                $notification_client->title = 'Votre commande a été récupérée avec succès 🎉';
                $notification_client->body =  "Votre commande #{$codeCommande} a bien été récupérée chez {$marchand->nom_marchand}. Merci pour votre confiance 🙏";
                $notification_client->role = 'client';
                $notification_client->id_user = $client->id;
                $notification_client->save();
                app(PushNotifController::class)->sendPush($notification_client);
            }

            if($marchand->device_token !== null){
                $notification_marchand = new Notification();
                $notification_marchand->type = 'commande_recuperation';
                $notification_marchand->title = "Commande #{$codeCommande} récupérée avec succès ✅";
                $notification_marchand->body = "Le client {$client->nom_client} a récupéré sa commande avec succès.";
                $notification_marchand->role = 'marchand';
                $notification_marchand->id_user = $marchand->id;
                $notification_marchand->save();
                app(PushNotifController::class)->sendPush($notification_marchand);
            }


            $alreadyCredited = Transaction::where('libelle', "Commande #{$codeCommande}")
                ->where('id_user', $marchand->id)
                ->exists();

            if (!$alreadyCredited) {
                $transaction = new Transaction();
                $transaction->amount = $totalPrice;
                $transaction->type = 'credit';
                $transaction->libelle = "Commande #{$codeCommande}";
                $transaction->id_user = $marchand->id;
                $transaction->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Sous-commandes récupérées avec succès',
                'data' => [
                    'id' => $commande->id,
                    'orderId' => $codeCommande,
                    'customerName' => $sousCommandes->first()->client->nom_client ?? 'Client',
                    'status' => $commande->statut,
                    'createdAt' => $commande->created_at,
                    'commission' => $sousCommandes->first()->commission ?? 0,
                    'totalPriceOrder' => $totalPrice,
                    'orderLength' => $totalQuantity,
                    'completedAt' => $sousCommandes->max('date_de_recuperation'),
                    'dishes' => $dishes
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne : ' . $e->getMessage()
            ], 500);
        }
    }




    public function sous_commandes_par_code(Request $request, $code_commande){
        
        try {
            $marchand = $request->user();

            // Récupère toutes les sous commandes de ce marchand pour ce code
            $sousCommandes = SousCommande::with(['plat', 'client'])
                ->where('code_commande', $code_commande)
                ->where('id_marchand', $marchand->id)
                ->get();

            if ($sousCommandes->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune sous-commande trouvée pour ce code.'
                ], 404);
            }

            $commandeId = $sousCommandes->first()->id_commande;
            $commande = Commande::find($commandeId);

            $clientName = $sousCommandes->first()->client->nom_client ?? "Client";
            $commission = $sousCommandes->first()->commission ?? 0;

            // Construction du payload
            $dishes = [];
            $totalPrice = 0;
            $totalQuantity = 0;

            foreach ($sousCommandes as $s) {
                $dishes[] = [
                    'id' => $s->id_plat,
                    'name' => $s->plat->nom_plat,
                    'quantity' => $s->quantite_plat,
                    'unit_price' => $s->plat->prix_reduit,
                    'code_qr' => $s->code_qr
                ];

                $totalPrice += $s->plat->prix_reduit * $s->quantite_plat;
                $totalQuantity += $s->quantite_plat;
            }

            $allRecovered = $sousCommandes->every(fn($i) => $i->date_de_recuperation !== null);

            $completedAt = $allRecovered
                ? \Carbon\Carbon::parse($sousCommandes->max('date_de_recuperation'))->toISOString()
                : null;

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $commande->id,
                    'orderId' => $code_commande,
                    'customerName' => $clientName,
                    'status' => $commande->statut,
                    'createdAt' => $commande->created_at,
                    'commission' => $commission,
                    'totalPriceOrder' => $totalPrice,
                    'orderLength' => $totalQuantity,
                    'completedAt' => $completedAt,
                    'dishes' => $dishes
                ],
                'message' => 'Commande affichée par code de la commande'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne : ' . $e->getMessage()
            ], 500);
        }
    }


    public function liste_commandes(Request $request){
        try {

            $sousCommandes = SousCommande::with([
                    'plat.marchand',
                    'client',
                    'commande'
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            if ($sousCommandes->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucune commande trouvée.'
                ], 200);
            }

            $grouped = $sousCommandes->groupBy('id_commande');
            $result = [];

            foreach ($grouped as $commandeId => $items) {

                $commande = $items->first()->commande;
                $client = $items->first()->client;
                $commission = $items->first()->commission ?? 0;

                $dishes = [];
                $marchands = [];
                $totalPrice = 0;
                $totalQuantity = 0;

                foreach ($items as $s) {

                    $montant = $s->plat->prix_reduit * $s->quantite_plat;
                    $totalPrice += $montant;
                    $totalQuantity += $s->quantite_plat;

                    $marchand = $s->plat->marchand;

                    if (!isset($marchands[$marchand->id])) {
                        $marchands[$marchand->id] = [
                            'id' => $marchand->id,
                            'nom' => $marchand->nom_marchand,
                            'image_profil' => $marchand->image_marchand,
                            'localite' => $marchand->commune->localite,
                            'telephone' => $marchand->tel_marchand ?? '',
                            'total_marchand' => 0
                        ];
                    }

                    $marchands[$marchand->id]['total_marchand'] += $montant;

                    $dishes[] = [
                        'id' => $s->id_plat,
                        'name' => $s->plat->nom_plat,
                        'quantity' => $s->quantite_plat,
                        'unit_price' => $s->plat->prix_reduit,
                        'code_qr' => $s->code_qr
                    ];
                }

                $allRecovered = $items->every(fn ($i) => $i->date_de_recuperation !== null);

                $completedAt = $allRecovered
                    ? \Carbon\Carbon::parse($items->max('date_de_recuperation'))->toISOString()
                    : null;

                $result[] = [
                    'id' => $commande->id,
                    'orderId' => $items->first()->code_commande,
                    'customerName' => $client->nom_client,
                    'status' => $commande->statut,
                    'createdAt' => $commande->created_at,
                    'commission' => $commission,
                    'totalPriceOrder' => $totalPrice,
                    'orderLength' => $totalQuantity,
                    'completedAt' => $completedAt,
                    'client' => [
                        'id' => $client->id,
                        'nom' => $client->nom_client,
                        'telephone' => $client->tel_client ?? ''
                    ],
                    'marchands' => array_values($marchands),
                    'dishes' => $dishes
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Liste des commandes'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de toutes les commandes',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }




}
