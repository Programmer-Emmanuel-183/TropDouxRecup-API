<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\CommissionEntreprise;
use App\Models\CommissionPremium;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CommissionController extends Controller
{
    public function commission(){
        try{
            $commission = Commission::first();
            if(!$commission){
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune commission trouvée'
                ],404);
            }
            return response()->json([
                'success' => true,
                'data' => $commission->pourcentage . '%',
                'message' => 'Pourcentage de la commission trouvée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la commission',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function commission_update(Request $request){
        $validator = Validator::make($request->all(), [
            'pourcentage' => 'required'
        ]);

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }
        try{

            $commission = Commission::first();

            if(!$commission){
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune commission trouvée'
                ],404);
            }

            $commission->pourcentage = $request->pourcentage ?? $commission->pourcentage;
            $commission->save();

            return response()->json([
                'success' => true,
                'data' => $commission->pourcentage . '%',
                'message' => 'Commission modifiée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => true,
                'message' => 'Erreur lors de la modification de la commission',
                'erreur' => $e->getMessage()
            ],500);
        }
    }


    public function commission_entreprise(){
        try{
            $commission = CommissionEntreprise::first();
            if(!$commission){
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune commission trouvée'
                ],404);
            }
            return response()->json([
                'success' => true,
                'data' => $commission->pourcentage . '%',
                'message' => 'Pourcentage de la commission trouvée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la commission',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function commission_update_entreprise(Request $request){
        $validator = Validator::make($request->all(), [
            'pourcentage' => 'required'
        ]);

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }
        try{

            $commission = CommissionEntreprise::first();

            if(!$commission){
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune commission trouvée'
                ],404);
            }

            $commission->pourcentage = $request->pourcentage ?? $commission->pourcentage;
            $commission->save();

            return response()->json([
                'success' => true,
                'data' => $commission->pourcentage . '%',
                'message' => 'Commission modifiée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => true,
                'message' => 'Erreur lors de la modification de la commission',
                'erreur' => $e->getMessage()
            ],500);
        }
    }


    public function commission_premium(){
        try{
            $commission = CommissionPremium::first();
            if(!$commission){
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune commission trouvée'
                ],404);
            }
            return response()->json([
                'success' => true,
                'data' => $commission->pourcentage . '%',
                'message' => 'Pourcentage de la commission trouvée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la commission',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function commission_update_premium(Request $request){
        $validator = Validator::make($request->all(), [
            'pourcentage' => 'required'
        ]);

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }
        try{

            $commission = CommissionPremium::first();

            if(!$commission){
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune commission trouvée'
                ],404);
            }

            $commission->pourcentage = $request->pourcentage ?? $commission->pourcentage;
            $commission->save();

            return response()->json([
                'success' => true,
                'data' => $commission->pourcentage . '%',
                'message' => 'Commission modifiée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => true,
                'message' => 'Erreur lors de la modification de la commission',
                'erreur' => $e->getMessage()
            ],500);
        }
    }
}
