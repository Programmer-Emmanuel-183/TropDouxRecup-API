<?php

namespace App\Http\Controllers;

use App\Models\Marchand;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function historiques_marchand(Request $request){
        try{
            $user = $request->user();
            $marchand = Marchand::find($user->id);
            if(!$marchand){
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand introuvable'
                ],404);
            }

            $transactions = Transaction::where('id_user', $marchand->id)->orderBy('created_at', 'desc')->get();
            if($transactions->isEmpty()){
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucunes transactions trouvées'
                ],200);
            }

            $data = $transactions->map(function($transaction){
                return [
                    'id' => $transaction->id,
                    'createdAt' => $transaction->created_at,
                    'amount' => $transaction->amount,
                    'type' => $transaction->type,
                    'libelle' => $transaction->libelle
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Historiques des transactions affichées avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => true,
                'message' => 'Erreur lors de l’affichage de l’historiques des transactions du marchand',
                'erreur' => $e->getMessage()
            ],500);
        }
    }
}
