<?php

namespace App\Http\Controllers;

use App\Models\Facturation;
use App\Models\Marchand;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class FacturationController extends Controller
{
    public function historiques_facturation(Request $request){
        try{
            $user = $request->user();
            $marchand = Marchand::find($user->id);
            if(!$marchand){
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand non trouvé'
                ],404);
            }

            $facturations = Facturation::where('id_user', $marchand->id)->orderBy('created_at', 'desc')->get();

            if($facturations->isEmpty()){
                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => $marchand->id,
                        'nom_abonnement' => $marchand->abonnement->type_abonnement,
                        'montant' => $marchand->abonnement->montant,
                        'date_abonnement' => $marchand->created_at
                    ],
                    'message' => 'Historique de facturations'
                ],200);
            }
            $data = $facturations->map(function($facturation){
                return [
                    'id' => $facturation->id,
                    'nom_abonnement' => $facturation->nom_abonnement,
                    'montant' => $facturation->montant,
                    'date_abonnement' => $facturation->created_at
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Historique de facturation affiché avec sucès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de l’historique de facturation',
                'erreur' => $e->getMessage()
            ],500);
        }
    }
}
