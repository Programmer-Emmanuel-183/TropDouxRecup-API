<?php

namespace App\Http\Controllers;

use App\Models\Avantage;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AvantageController extends Controller
{
    public function liste_avantage(Request $request){
        try{
            $avantage = Avantage::select('id', 'nom_avantage', 'value', 'created_at')->get();
            return response()->json([
                'success' => true,
                'data' => $avantage,
                'message' => 'Liste des avantages affichées avec succès'
            ],200);

        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage des avantages',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function ajout_avantage(Request $request){
        $validator = Validator::make($request->all(), [
            'nom_avantage' => 'required',
            'value' => 'required'
        ],[
            'nom_avantage.required' => 'Le nom de l’avantage est requis.',
            'value.required' => 'Le nom de l’avantage est requis.'
        ]);

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $avantage = new Avantage();
            $avantage->nom_avantage = $request->nom_avantage;
            $avantage->value = $request->value ?? null;
            $avantage->save();

            return response()->json([
                'success' => true,
                'data' => $avantage,
                'message' => 'Ajout de l’avantage réussie'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’ajout des avantages',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function update_avantage(Request $request, $id){
        $validator = Validator::make($request->all(), [
            'nom_avantage' => 'required',
            'value' => 'required'
        ],[
            'nom_avantage.required' => 'Le nom de l’avantage est requis.',
            'value.required' => 'Le nom de l’avantage est requis.'
        ]);

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $avantage = Avantage::find($id);
            if(!$avantage){
                return response()->json([
                    'success' => false,
                    'message' => 'Avantage non trouvé'
                ],404);
            }

            $avantage->nom_avantage = $request->nom_avantage;
            $avantage->save();

            return response()->json([
                'success' => true,
                'data' => $avantage,
                'message' => 'Modification de l’avantage réussie'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification de l’avantage',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function delete_avantage(Request $request, $id){
        try{
            $avantage = Avantage::find($id);
            if(!$avantage){
                return response()->json([
                    'success' => false,
                    'message' => 'Avantage non trouvé'
                ],404);
            }
            
            $avantage->delete();
            return response()->json([
                'success' => true,
                'message' => 'Avantage supprimé avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l’avantage',
                'erreur' => $e->getMessage()
            ],500);
        }
    }
}
