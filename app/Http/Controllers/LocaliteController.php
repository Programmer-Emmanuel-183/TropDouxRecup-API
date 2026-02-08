<?php

namespace App\Http\Controllers;

use App\Models\Commune;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LocaliteController extends Controller
{
    public function localites(){
        try{
            $localites = Commune::orderBy('localite', 'desc')->get();
            if($localites->isEmpty()){
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucune localite trouvee'
                ],200);
            }

            $data = $localites->map(function($localite){
                return [
                    'id' => $localite->id,
                    'libelle' => $localite->localite,
                    'created_at' => $localite->created_at
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Localite affichées avec succès.'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la liste des localites',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function localite($id){
        try{
            $localite = Commune::find($id);
            if(!$localite){
                return response()->json([
                    'success' => false,
                    'message' => 'Localite non trouvé'
                ],404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $localite->id,
                    'libelle' => $localite->localite,
                    'created_at' => $localite->created_at
                ],
                'message' => 'Localite affichée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'messsage' => 'Erreur lors de l’affichage de la localite',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function ajout_localite(Request $request){
        $validator = Validator::make($request->all(), [
            'localite' => 'required|unique:communes'
        ]);
        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $localite = new Commune();
            $localite->localite = $request->localite;
            $localite->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $localite->id,
                    'localite' => $localite->localite 
                ],
                'message' => 'Localite ajoutée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’ajout de la localite',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function update_localite(Request $request, $id){
        $validator = Validator::make($request->all(), [
            'localite' => 'required'
        ]);
        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $localite = Commune::find($id);
            if(!$localite){
                return response()->json([
                    'success' => false,
                    'message' => 'Localite non trouve'
                ],404);
            }

            $localite->localite = $request->localite;
            $localite->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $localite->id,
                    'localite' => $localite->localite 
                ],
                'message' => 'Localite modifiée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’ajout de la localite',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function delete_localite(Request $request, $id){
        try{
            $localite = Commune::find($id);
            if(!$localite){
                return response()->json([
                    'success' => false,
                    'message' => 'Localite non trouve'
                ],404);
            }

            $localite->delete();

            return response()->json([
                'success' => true,
                'message' => 'Localite supprimé avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la localite',
                'erreur' => $e->getMessage()
            ],500);
        }
    }
}
