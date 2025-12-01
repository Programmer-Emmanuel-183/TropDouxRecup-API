<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class CategorieController extends Controller
{
    public function ajout_categorie(Request $request){
        $validator = Validator::make($request->all(),[
            'nom_categorie' => 'required',
            'image_categorie' => 'required|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $image = $this->uploadImageToHosting($request->file('image_categorie'));

            $categorie = new Categorie();
            $categorie->nom_categorie = $request->nom_categorie;
            $categorie->image_categorie = $image;
            $categorie->save();

            return response()->json([
                'success' => true,
                'data' => $categorie,
                'message' => 'Ajout de categorie réussie'
            ],200);


        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’ajout de categorie',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function liste_categorie(){
        try{
            $categorie = Categorie::all();

            if(empty($categorie)){
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucune categorie trouvée'
                ],200);
            }

            return response()->json([
                'success' => true,
                'data' => $categorie,
                'message' => 'Liste des categories affichée avec succès.'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la liste des categories',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function categorie($id){
        try{
            $categorie = Categorie::find($id);

            if(!$categorie){
                return response()->json([
                    'success' => false,
                    'message' => 'Categorie non trouvée'
                ],404);
            }

            return response()->json([
                'success' => true,
                'data' => $categorie,
                'message' => 'Categorie affichée avec succès.'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la categories',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function delete_categorie(Request $request, $id){
        try{
            $categorie = Categorie::find($id);
            if(!$categorie){
                return response()->json([
                    'success' => false,
                    'message' => 'Categorie non trouvée'
                ],404);
            }

            $categorie->delete();
            return response()->json([
                'success' => true,
                'message' => 'Categorie supprimé avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression d’une categorie',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function update_categorie(Request $request, $id){
        $validator = Validator::make($request->all(),[
            'nom_categorie' => 'required',
            'image_categorie' => 'required|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $image = $this->uploadImageToHosting($request->file('image_categorie'));

            $categorie = Categorie::find($id);
            if(!$categorie){
                return response()->json([
                    'success' => false,
                    'message' => 'Categorie non trouvée'
                ],404);
            }
            $categorie->nom_categorie = $request->nom_categorie ?? $categorie->nom_categorie;
            $categorie->image_categorie = $image ?? $categorie->image_categorie;
            $categorie->save();

            return response()->json([
                'success' => true,
                'data' => $categorie,
                'message' => 'Modification de la categorie réussie'
            ],200);


        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification de la categorie',
                'erreur' => $e->getMessage()
            ],500);
        }
    }



    private function uploadImageToHosting($image){
        $apiKey = '9b1ab6564d99aab6418ad53d3451850b';

        // Vérifie que le fichier est une instance valide
        if (!$image->isValid()) {
            throw new \Exception("Fichier image non valide.");
        }

        // Lecture et encodage en base64
        $imageContent = base64_encode(file_get_contents($image->getRealPath()));

        $response = Http::asForm()->timeout(30)->post('https://api.imgbb.com/1/upload', [
            'key' => $apiKey,
            'image' => $imageContent,
        ]);

        if ($response->successful()) {
            return $response->json()['data']['url'];
        }

        throw new \Exception("Erreur lors de l'envoi de l'image : " . $response->body());
    }
}
