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

    public function favoris(Request $request){
        try {

            $user = $request->user();

            $client = User::find($user->id);

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable'
                ], 404);
            }

            // type par défaut = plat
            $type = $request->query('type', 'plat');

            /*
            |--------------------------------------------------------------------------
            | FAVORIS MARCHANDS
            |--------------------------------------------------------------------------
            */
            if ($type === 'merchant') {

                $favoris = FavorisMarchand::with([
                        'marchand.commune'
                    ])
                    ->where('id_client', $client->id)
                    ->orderBy('created_at', 'desc')
                    ->get();

                if ($favoris->isEmpty()) {
                    return response()->json([
                        'success' => true,
                        'data' => [],
                        'message' => 'Aucun marchand mis en favoris'
                    ], 200);
                }

                $data = $favoris->map(function ($fav) {
                    return [
                        'id' => $fav->marchand->id,
                        'image' => $fav->marchand->image_marchand,
                        'nom' => $fav->marchand->nom_marchand,
                        'localite' => $fav->marchand->commune->localite ?? null,
                    ];
                });

                return response()->json([
                    'success' => true,
                    'data' => $data,
                    'message' => 'Liste des marchands favoris récupérée'
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | FAVORIS PLATS (DEFAULT)
            |--------------------------------------------------------------------------
            */
            $favoris = FavorisPlat::with([
                    'plat.marchand'
                ])
                ->where('id_client', $client->id)
                ->orderBy('created_at', 'desc')
                ->get();

            if ($favoris->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucun plat mis en favoris'
                ], 200);
            }

            $data = $favoris->map(function ($fav) {
                return [
                    'id' => $fav->plat->id,
                    'image' => $fav->plat->image_couverture,
                    'nom_plat' => $fav->plat->nom_plat,
                    'prix' => $fav->plat->prix_reduit,
                    'old_prix' => $fav->plat->prix_origine,
                    'nom_marchand' => $fav->plat->marchand->nom_marchand ?? null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Liste des plats favoris récupérée'
            ]);

        } catch (QueryException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des favoris',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }

}
