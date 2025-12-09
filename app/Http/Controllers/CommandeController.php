<?php

namespace App\Http\Controllers;

use App\Models\Commande;
use App\Models\Commission;
use App\Models\Panier;
use App\Models\SousCommande;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class CommandeController extends Controller
{
    public function passer_commande(Request $request)
    {
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
            $commande->statut = "pending";
            $commande->save();

            foreach ($paniers as $panier) {

                $plat = $panier->plat;
                $plat->quantite_disponible -= $panier->quantite;
                $plat->save();

                $sous = new SousCommande();
                $sous->commission = $commission->pourcentage ?? 0;
                $sous->id_commande = $commande->id;
                $sous->id_client = $client->id;
                $sous->id_plat = $panier->id_plat;
                $sous->quantite_plat = $panier->quantite;
                $sous->id_marchand = $panier->plat->id_marchand;
                $sous->statut = 'pending';
                $sous->code_commande = "TDR-" . strtoupper(substr($commande->id, 0, 6));
                $svg = QrCode::format('svg')->size(200)->generate($sous->code_commande);
                $sous->code_qr = 'data:image/svg+xml;base64,' . base64_encode($svg);
                $sous->save();
            }

            Panier::where('id_client', $client->id)
                ->whereIn('id', $request->id_item)
                ->delete();

            $sousCommandes = SousCommande::with('plat')
                ->where('id_commande', $commande->id)
                ->get();

            $orderId = $sousCommandes->first()->code_commande;

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

            return response()->json([
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
            ]);

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

            $query = SousCommande::with(['plat'])
                ->where('id_marchand', $marchand->id)
                ->orderBy('created_at', 'desc');

            if($limit){
                $query->limit($limit);
            }
            $sousCommandes = $query->get();

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
                        // 'code_qr' => $s->code_qr
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
                'data' => $result,
                'message' => 'Commandes du marchand affiché avec succès'
            ],200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne : ' . $e->getMessage()
            ], 500);
        }
    }

    public function marquer_comme_recupere(Request $request){
        $validator = Validator::make($request->all(), [
            'id_commande' => 'required|exists:commandes,id',
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

            // Récupérer les sous-commandes du marchand pour cette commande
            $sousCommandes = SousCommande::with(['plat', 'client'])
                ->where('id_commande', $request->id_commande)
                ->where('id_marchand', $marchand->id)
                ->get();

            if ($sousCommandes->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => "Aucune sous-commande trouvée pour ce marchand."
                ], 404);
            }

            // Marquer chaque sous-commande comme récupérée
            foreach ($sousCommandes as $s) {
                $s->statut = 'completed';
                $s->date_de_recuperation = $dateNow;
                $s->save();
            }

            // Mettre à jour la commande principale si toutes les sous-commandes sont récupérées
            $commande = Commande::find($request->id_commande);
            $allRecovered = SousCommande::where('id_commande', $commande->id)
                ->whereNull('date_de_recuperation')
                ->doesntExist();

            if ($allRecovered) {
                $commande->statut = 'completed';
                $commande->save();
            }

            // Construction de la réponse
            $result = [];

            $grouped = $sousCommandes->groupBy('id_commande');

            foreach ($grouped as $commandeId => $items) {

                $orderId = $items->first()->code_commande;
                $commission = $items->first()->commission ?? 0;
                $clientName = $items->first()->client->nom_client ?? "Client";

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

                $completedAt = $items->max('date_de_recuperation')->toISOString();

                $result[] = [
                    'id' => $commande->id,
                    'orderId' => $orderId,
                    'customerName' => $clientName,
                    'status' => $commande->statut, // maintenant correct !
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
                'message' => "Commande marquée comme récupérée.",
                'data' => $result
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



}
