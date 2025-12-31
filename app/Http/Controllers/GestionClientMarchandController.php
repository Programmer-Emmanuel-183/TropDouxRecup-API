<?php

namespace App\Http\Controllers;

use App\Models\Marchand;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class GestionClientMarchandController extends Controller
{
    public function liste_client(Request $request){
        try{
            $clients = User::orderBy('created_at', 'desc')->get();
            if($clients->isEmpty()){
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucun client trouvé'
                ],200);
            }

            $data = $clients->map(function($client){
                return [
                    'id' => $client->id,
                    'nom' => $client->nom_client,
                    'email' => $client->email_client,
                    'telephone' => $client->tel_client,
                    'image' => $client->image_client,
                    'device_token' => $client->device_token,
                    'created_at' => $client->created_at,
                    'updated_at' => $client->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Liste des clients affichée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la liste des clients',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function client(Request $request, $id){
        try{
            $client = User::find($id);

            if(!$client){
                return response()->json([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ],404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $client->id,
                    'nom' => $client->nom_client,
                    'email' => $client->email_client,
                    'telephone' => $client->tel_client,
                    'image' => $client->image_client,
                    'device_token' => $client->device_token,
                    'created_at' => $client->created_at,
                    'updated_at' => $client->updated_at,
                ],
                'message' => 'Client affiché avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage du client',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function delete_client(Request $request, $id){
        try{
            $client = User::find($id);
            if(!$client){
                return response()->json([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ]);
            }
            $client->delete();

            return response()->json([
                'success' => true,
                'message' => 'Client supprimé avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du client',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function liste_marchand(Request $request){
        try{
            $marchands = Marchand::where('is_verify', true)
                ->orderBy('created_at', 'desc')
                ->get();

            if($marchands->isEmpty()){
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucun marchand trouvé'
                ],200);
            }

            $data = $marchands->map(function($marchand){
                return [
                    'id' => $marchand->id,
                    'nom' => $marchand->nom_marchand,
                    'email' => $marchand->email_marchand,
                    'telephone' => $marchand->tel_marchand,
                    'image' => $marchand->image_marchand,
                    'device_token' => $marchand->device_token,
                    'localite' => $marchand->commune->localite,
                    'abonnement' => $marchand->abonnement->type_abonnement,
                    'is_active' => $marchand->is_active,
                    'created_at' => $marchand->created_at
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Liste des marchands affichée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la liste des marchand',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function marchand(Request $request, $id){
        try{
            $marchand = Marchand::find($id);
            if(!$marchand || $marchand->is_verify == false){
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand non trouvé'
                ],404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $marchand->id,
                    'nom' => $marchand->nom_marchand,
                    'email' => $marchand->email_marchand,
                    'telephone' => $marchand->tel_marchand,
                    'image' => $marchand->image_marchand,
                    'device_token' => $marchand->device_token,
                    'localite' => $marchand->commune->localite,
                    'abonnement' => $marchand->abonnement->type_abonnement,
                    'is_active' => $marchand->is_active,
                    'created_at' => $marchand->created_at
                ],
                'message' => 'Marchand affiché avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage du marchand',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function delete_marchand(Request $request, $id){
        try{
            $marchand = Marchand::find($id);

            if(!$marchand){
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand introuvable'
                ],404);
            }

            $marchand->delete();

            return response()->json([
                'success' => true,
                'message' => 'Marchand supprimé avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du marchand',
                'erreur' => $e->getMessage()
            ],500);
        }
    }
}
