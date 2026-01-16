<?php

namespace App\Http\Controllers;

use App\Models\Avis;
use App\Models\Marchand;
use App\Models\Plat;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AvisController extends Controller
{
    public function ajout_avis(Request $request, $id_plat){
        $validator = Validator::make($request->all(), [
            'etoile' => 'required|between:1,5|integer', 
            'commentaire' => 'nullable'
        ]);
        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }
        try{
            $user = $request->user();

            $client = User::find($user->id);
            if(!$client){
                return response()->json([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ],404);
            }

            $plat = Plat::find($id_plat);
            if(!$plat){
                return response()->json([
                    'success' => false,
                    'message' => 'Plat non trouvé'
                ],404);
            }

            $marchand = Marchand::find($plat->id_marchand);
            if(!$marchand){
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand non trouvé'
                ],404);
            }

            $avis = new Avis();
            $avis->etoile = $request->etoile;
            $avis->commentaire = $request->commentaire ?? null;
            $avis->id_plat = $plat->id;
            $avis->id_client = $client->id;
            $avis->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $avis->id,
                    'etoile' => (int) $avis->etoile,
                    'commentaire' => $avis->commentaire,
                    'plat' => [
                        'id' => $plat->id,
                        'nom' => $plat->nom_plat,
                        'image_couverture' => $plat->image_couverture,
                    ],
                    'marchand' => [
                        'id' => $marchand->id,
                        'nom' => $marchand->nom_marchand,
                        'image_marchand' => $marchand->image_marchand
                    ],
                    'client' => [
                        'id' => $client->id,
                        'nom' => $client->nom_client,
                        'image_client' => $client->image_client
                    ]
                ],
                'message' => 'Avis ajoutée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’ajout d’avis',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function avis(Request $request){
        try {
            $avis = Avis::with(['plat.marchand', 'client'])
                ->orderBy('created_at', 'desc')
                ->get();

            if ($avis->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucun avis trouvé.'
                ], 200);
            }

            $data = $avis->map(function ($avi) {
                return [
                    'id' => $avi->id,
                    'etoile' => (int) $avi->etoile,
                    'commentaire' => $avi->commentaire,
                    'plat' => $avi->plat ? [
                        'id' => $avi->plat->id,
                        'nom' => $avi->plat->nom_plat,
                        'image_couverture' => $avi->plat->image_couverture,
                    ] : null,
                    'marchand' => $avi->plat && $avi->plat->marchand ? [
                        'id' => $avi->plat->marchand->id,
                        'nom' => $avi->plat->marchand->nom_marchand,
                        'image_marchand' => $avi->plat->marchand->image_marchand
                    ] : null,
                    'client' => $avi->client ? [
                        'id' => $avi->client->id,
                        'nom' => $avi->client->nom_client,
                        'image_client' => $avi->client->image_client
                    ] : null
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Affichage des avis'
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la liste des avis',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }


    public function avis_marchand(Request $request)
{
    try {
        $user = $request->user();

        // ⚠️ suppose que marchand.id === user.id
        $marchand = Marchand::find($user->id);
        if (!$marchand) {
            return response()->json([
                'success' => false,
                'message' => 'Marchand non trouvé'
            ], 404);
        }

        $avis = Avis::whereHas('plat', function ($query) use ($marchand) {
                $query->where('id_marchand', $marchand->id);
            })
            ->with(['plat', 'plat.marchand', 'client'])
            ->orderBy('created_at', 'desc')
            ->get();

        $data = $avis->map(function ($avi) {

            $plat = $avi->plat;
            $marchand = $plat?->marchand;
            $client = $avi->client;

            return [
                'id' => $avi->id,
                'etoile' => (int) $avi->etoile,
                'commentaire' => $avi->commentaire,

                'plat' => $plat ? [
                    'id' => $plat->id,
                    'nom' => $plat->nom_plat,
                    'image_couverture' => $plat->image_couverture,
                ] : null,

                'marchand' => $marchand ? [
                    'id' => $marchand->id,
                    'nom' => $marchand->nom_marchand,
                    'image_marchand' => $marchand->image_marchand,
                ] : null,

                'client' => $client ? [
                    'id' => $client->id,
                    'nom' => $client->nom_client,
                    'image_client' => $client->image_client,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => 'Avis du marchand affichés avec succès'
        ], 200);

    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de l’affichage des avis du marchand',
            'erreur' => $e->getMessage()
        ], 500);
    }
}


    public function delete_avis(Request $request, $id){
        try{
            $avis = Avis::find($id);
            if(!$avis){
                return response()->json([
                    'success' => false,
                    'message' => 'Avis introuvable'
                ],404);
            }

            $avis->delete();

            return response()->json([
                'success' => true,
                'message' => 'Avis supprimé avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression d’un avis',
                'erreur' => $e->getMessage()
            ],500);
        }
    }
}
