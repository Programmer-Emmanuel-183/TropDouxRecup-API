<?php

namespace App\Http\Controllers;

use App\Models\FavorisMarchand;
use App\Models\FavorisPlat;
use App\Models\Marchand;
use App\Models\Plat;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FavorisController extends Controller
{
    public function ajout_plat_favoris(Request $request){
        $validator = Validator::make($request->all(), [
            'id_plat' => 'required|exists:plats,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $client = $request->user();

            $favori = FavorisPlat::where('id_client', $client->id)
                ->where('id_plat', $request->id_plat)
                ->first();

            // ❌ DÉJÀ EN FAVORIS → SUPPRIMER
            if ($favori) {
                $favori->delete();

                return response()->json([
                    'success' => true,
                    'action' => 'delete',
                    'message' => 'Plat retiré des favoris'
                ], 200);
            }

            // ✅ AJOUTER
            FavorisPlat::create([
                'id_client' => $client->id,
                'id_plat' => $request->id_plat
            ]);

            return response()->json([
                'success' => true,
                'action' => 'ajout',
                'message' => 'Plat ajouté en favoris'
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la gestion des favoris',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }


    public function ajout_marchand_favoris(Request $request){
        $validator = Validator::make($request->all(), [
            'id_marchand' => 'required|exists:marchands,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $client = $request->user();

            $favori = FavorisMarchand::where('id_client', $client->id)
                ->where('id_marchand', $request->id_marchand)
                ->first();

            // ❌ DÉJÀ EN FAVORIS → SUPPRIMER
            if ($favori) {
                $favori->delete();

                return response()->json([
                    'success' => true,
                    'action' => 'delete',
                    'message' => 'Marchand retiré des favoris'
                ], 200);
            }

            // ✅ AJOUTER
            FavorisMarchand::create([
                'id_client' => $client->id,
                'id_marchand' => $request->id_marchand
            ]);

            return response()->json([
                'success' => true,
                'action' => 'ajout',
                'message' => 'Marchand ajouté en favoris'
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la gestion des favoris',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }

    public function plats_favoris(Request $request){
        try{
            $user = $request->user();
            $client = User::find($user->id);
            if(!$client){
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable'
                ],404);
            }

            $favoris_plats = FavorisPlat::where('id_client', $client->id)->orderBy('created_at', 'desc')->get();
            if($favoris_plats->isEmpty()){
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucun plats mis en favoris'
                ],200);
            }

            $data = $favoris_plats->map(function($favoris_plat){
                return [
                    'id' => $favoris_plat->plat->id,
                    'image' => $favoris_plat->plat->image_couverture,
                    'prix' => $favoris_plat->plat->prix_reduit,
                    'nom_marchand' => $favoris_plat->plat->marchand->nom_marchand 
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Liste des plats mis en favoris affichés avec succès'
            ],200);


        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage des plats en favoris',
                'erreur' => $e->getMessage()
            ],500);
        }
    }


    public function marchands_favoris(Request $request){
        try{
            $user = $request->user();
            $client = User::find($user->id);
            if(!$client){
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable'
                ],404);
            }

            $favoris_marchands = FavorisMarchand::where('id_client', $client->id)->orderBy('created_at', 'desc')->get();
            if($favoris_marchands->isEmpty()){
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucun marchands mis en favoris'
                ],200);
            }

            $data = $favoris_marchands->map(function($favoris_marchand){
                return [
                    'id' => $favoris_marchand->marchand->id,
                    'image' => $favoris_marchand->marchand->image_marchand,
                    'nom' => $favoris_marchand->marchand->nom_marchand,
                    'localite' => $favoris_marchand->marchand->commune->localite,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Liste des marchands mis en favoris affichés avec succès'
            ],200);


        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage des marchands en favoris',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

}
