<?php

namespace App\Http\Controllers;

use App\Models\Assistance;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AssistanceController extends Controller
{
    public function ajout_assistance(Request $request){
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:telephone,whatsapp',
            'description' => 'nullable',
            'contact' => 'required'
        ]);
        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $assistance = new Assistance();
            $assistance->type = $request->type;
            $assistance->description = $request->description;
            $assistance->contact = $request->contact;
            $assistance->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $assistance->id,
                    'type' => $assistance->type,
                    'description' => $assistance->description,
                    'contact' => $assistance->contact
                ],
                'message' => 'Assistance ajoutée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’ajout d’assistance',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function assistances(Request $request){
        try{
            $assistances = Assistance::all();
            if($assistances->isEmpty()){
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucune assistance trouvée'
                ],200);
            }
            return response()->json([
                'success' => true,
                'data' => $assistances,
                'message' => 'Liste des assistances affichées avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la liste des assistances',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function assistance(Request $request, $id){
        try{
            $assistance = Assistance::find($id);
            if(!$assistance){
                return response()->json([
                    'success' => false,
                    'message' => 'Assistance non trouvée',
                ],404);
            }

            return response()->json([
                'success' => true,
                'data' => $assistance,
                'message' => 'Assistance affichée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de l’assistance',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function update_assistance(Request $request, $id){
        $validator = Validator::make($request->all(),[
            'type' => 'string|in:telephone,whatsapp',
            'description' => 'nullable',
            'contact' => 'nullable'
        ]);
        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $assistance = Assistance::find($id);
            if(!$assistance){
                return response()->json([
                    'success' => false,
                    'message' => 'Assistance non trouvée'
                ],404);
            }

            $assistance->type = $request->type ?? $assistance->type;
            $assistance->description = $request->description ?? $assistance->description;
            $assistance->contact = $request->contact ?? $assistance->contact;
            $assistance->save();

            return response()->json([
                'success' => true,
                'data' => $assistance,
                'message' => 'Assistance mis à jour avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de l’assistance',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function delete_assistance(Request $request, $id){
        try{
            $assistance = Assistance::find($id);
            if(!$assistance){
                return response()->json([
                    'success' => false,
                    'message' => 'Assistance non trouvée'
                ],404);
            }
            $assistance->delete();
            return response()->json([
                'success' => true,
                'message' => 'Assistance supprimée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l’assistance',
                'erreur' => $e->getMessage()
            ],500);
        }
    }
}
