<?php

namespace App\Http\Controllers;

use App\Models\Panier;
use App\Models\Plat;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PanierController extends Controller
{
    public function ajout_panier(Request $request){

        $validator = Validator::make($request->all(), [
            'quantite' => 'required',
            'id_plat' => 'required'
        ]);
        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $user = $request->user();
            if(!$user){
                return response()->json([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ],404);
            }

            $plat = Plat::find($request->id_plat);
            if(!$plat){
                return response()->json([
                    'success' => false,
                    'message' => 'Plat non trouvé'
                ],404);
            }
            if($plat->quantite_disponible < $request->quantite){
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez pas ajouter plus que la quantité disponible du plat au panier'
                ],400);
            }

            $panier = new Panier();
            $panier->quantite = $request->quantite;
            $panier->id_plat = $request->id_plat;
            $panier->id_client = $user->id;
            $panier->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'id_item' => $panier->id,
                    'id_client' => $panier->id_client,
                    'id_plat' => $plat->id,
                    'nom_plat' => $plat->nom_plat,
                    'image' => $plat->image_couverture,
                    'nom_marchandd' => $plat->marchand->nom_marchand,
                    'prix' => $plat->prix_reduit,
                    'quantite' => $panier->quantite,
                ],
                'message' => 'Plat ajouté au panier avec succès.'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’ajout du plat dans le panier',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function panier(Request $request){
        try {
            $client = $request->user();

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ], 404);
            }

            $panier = Panier::where('id_client', $client->id)->get();

            if ($panier->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Panier vide'
                ], 200);
            }

            $groupes = [];

            foreach ($panier as $item) {

                $plat = Plat::with('marchand')->find($item->id_plat);
                if (!$plat || !$plat->marchand) {
                    continue;
                }

                $marchandId = $plat->marchand->id;

                // Initialisation du marchand
                if (!isset($groupes[$marchandId])) {
                    $groupes[$marchandId] = [
                        'marchand_id' => $marchandId,
                        'marchand_nom' => $plat->marchand->nom_marchand,
                        'marchand_image' => $plat->marchand->image_marchand ?? null,
                        'total' => 0,
                        'plats' => []
                    ];
                }

                $prixOrigineTotal = $plat->prix_origine * $item->quantite;
                $prixReduitTotal  = $plat->prix_reduit * $item->quantite;

                // Ajout du plat
                $groupes[$marchandId]['plats'][] = [
                    'id_item' => $item->id,
                    'id_plat' => $plat->id,
                    'nom_plat' => $plat->nom_plat,
                    'image' => $plat->image_couverture,
                    'quantite' => $item->quantite,
                    'prix_origine' => $plat->prix_origine,
                    'prix_reduit' => $plat->prix_reduit,
                ];

                // Total par marchand (prix réduit comme dans ton mock)
                $groupes[$marchandId]['total'] += $prixReduitTotal;
            }

            return response()->json([
                'success' => true,
                'data' => array_values($groupes),
                'message' => 'Panier récupéré avec succès'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du panier',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }

    public function add_quantite(Request $request, $id_item){
        try{
            $panier = Panier::find($id_item);
            if(!$panier){
                return response()->json([
                    'success' => false,
                    'message' => 'Plat introuvable'
                ],404);
            }



            if($panier->quantite >= $panier->plat->quantite_disponible){
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez pas ajouté de plat au dessus de la quantite de plat disponible'
                ],400);
            }

            $panier->quantite += 1;
            $panier->save();
            return response()->json([
                'success' => true,
                'message' => 'Quantite ajoutée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’augmentation de la quantité du plat',
                'erreur' => $e->getMessage()
            ],500);
        }
    }


    public function baisse_quantite(Request $request, $id_item){
        try{
            $panier = Panier::find($id_item);
            if(!$panier){
                return response()->json([
                    'success' => false,
                    'message' => 'Plat introuvable'
                ],404);
            }



            if($panier->quantite === 1){
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez plus diminuer la quantité'
                ],400);
            }

            $panier->quantite -= 1;
            $panier->save();
            return response()->json([
                'success' => true,
                'message' => 'Quantite diminuée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la diminution de la quantité du plat',
                'erreur' => $e->getMessage()
            ],500);
        }
    }



    public function delete_plat(Request $request, $id_item){
        try{
            $panier = Panier::find($id_item);
            if(!$panier){
                return response()->json([
                    'success' => false,
                    'message' => 'Ce plat n’est pas touvé dans le panier'
                ],404);
            }

            $panier->delete();
            return response()->json([
                'success' => true,
                'message' => 'Plat suprimmé du panier avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du plat dans le panier',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

}
