<?php

namespace App\Http\Controllers;

use App\Mail\CompteMarchandActive;
use App\Mail\CompteMarchandDesactive;
use App\Models\ActivationCompte;
use App\Models\Marchand;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ActivationCompteController extends Controller
{
    public function choix_activation(Request $request){
        try{
            $activation = ActivationCompte::first();
            if(!$activation){
                return response()->json([
                    'success' => false,
                    'message' => 'Choix d’activation non trouvé'
                ],404);
            }

            return response()->json([
                'success' => true,
                'data' => $activation->activate,
                'message' => 'Choix d’activation trouvé'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage du choix d’activation de compte',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function update_choix(Request $request){
        $validator = Validator::make($request->all(),[
            'choix' => 'required|in:0,1'
        ]);

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $activation = ActivationCompte::first();
            if(!$activation){
                return response()->json([
                    'success' => false,
                    'message' => 'Choix d’activation non trouvé'
                ],404);
            }

            if($request->choix == "1"){
                $activation->activate = true;
                $activation->save();
            }
            if($request->choix == "0"){
                $activation->activate = false;
                $activation->save();
            }

            return response()->json([
                'success' => true,
                'data' => $activation->activate === true ? 1 : 0,
                'message' => 'Choix modifié avec succès'
            ]);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification du choix d’activation',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function valider_compte(Request $request, $id_marchand){
        try{
            $marchand = Marchand::find($id_marchand);
            if(!$marchand){
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand introuvable !'
                ],404);
            }
            if($marchand->is_active == true){
                return response()->json([
                    'success' => false,
                    'message' => 'Ce compte marchand a déjà été activé.'
                ],400);
            }

            $marchand->is_active = true;
            $marchand->save();

            // Mail::to($marchand->email_marchand)
            //     ->send(new CompteMarchandActive($marchand));
            
            return response()->json([
                'success' => true,
                'message' => 'Compte marchand activé avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la validation d’un compte',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function desactiver_compte(Request $request, $id_marchand){
        try{
            $marchand = Marchand::find($id_marchand);
            if(!$marchand){
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand introuvable !'
                ],404);
            }
            if($marchand->is_active == false){
                return response()->json([
                    'success' => false,
                    'message' => 'Ce compte marchand a déjà été descativé.'
                ],400);
            }

            $marchand->is_active = false;
            $marchand->save();

            // Mail::to($marchand->email_marchand)
            //     ->send(new CompteMarchandDesactive($marchand));
            
            return response()->json([
                'success' => true,
                'message' => 'Compte marchand desactivé avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la desactivation d’un compte',
                'erreur' => $e->getMessage()
            ],500);
        }
    }
}
