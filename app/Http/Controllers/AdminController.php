<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function afficher_solde(Request $request){
        $user = $request->user();
        if(!$user){
            return response()->json([
                'success' => false,
                'message' => 'Admin non trouvé'
            ],404);
        }

        if($user->role != 2){
            return response()->json([
                'success' => true,
                'data' => 0,
                'message' => 'Accès non permis'
            ],200);
        }

        try{
            return response()->json([
                'succcess' => true,
                'data' => $user->solde,
                'message' => 'Solde affiché avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage du solde du super admin',
                'erreur' => $e->getMessage()
            ],500);
        }
    }
}
