<?php

namespace App\Http\Controllers;

use App\Models\Publicite;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class PubliciteController extends Controller
{
    public function ajout_publicite(Request $request){
        $validator = Validator::make($request->all(), [
            'image_url' => 'required|image|mimes:png,jpg,jpeg|max:2048'
        ]);
        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first() 
            ],422);
        }

        try{
            $image = null;

            if ($request->hasFile('image_url')) {
                $image = $this->uploadImageToHosting($request->file('image_url'));
            }
            
            $publicite = new Publicite();
            $publicite->image_url = $image;
            $publicite->save();

            return response()->json([
                'success' => true,
                'data' => $publicite,
                'message' => 'Publicite ajoutée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’ajout de publicite',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function update_publicite(Request $request, $id){
        $validator = Validator::make($request->all(), [
            'image_url' => 'required|image|mimes:png,jpg,jpeg|max:2048'
        ]);
        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first() 
            ],422);
        }

        try{
            $image = null;

            if ($request->hasFile('image_url')) {
                $image = $this->uploadImageToHosting($request->file('image_url'));
            }
            
            $publicite = Publicite::find($id);
            if(!$publicite) {
                return response()->json([
                    'success' => false,
                    'message' => 'Publicité introuvable'
                ],404);
            }
            $publicite->image_url = $image ?? $publicite->image_url;
            $publicite->save();

            return response()->json([
                'success' => true,
                'data' => $publicite,
                'message' => 'Publicite ajoutée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’ajout de publicite',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function liste_paginate_publicite(Request $request){
        try{
            $publicites = Publicite::orderBy('created_at', 'desc')->limit(15)->get();
            if($publicites->isEmpty()){
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucune publicité pour le moment'
                ],200);
            }

            $data = $publicites->map(function($publicite){
                return [
                    'id' => $publicite->id,
                    'image_url' => $publicite->image_url
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Liste des publicités affichées avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la liste des publicités',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function publicites(Request $request){
        try{
            $publicites = Publicite::orderBy('created_at', 'desc')->get();
            if($publicites->isEmpty()){
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucune publicité pour le moment'
                ],200);
            }

            $data = $publicites->map(function($publicite){
                return [
                    'id' => $publicite->id,
                    'image_url' => $publicite->image_url,
                    'created_at' => $publicite->created_at
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Liste des publicités affichées avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la liste des publicités',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function publicite(Request $request, $id){
        try{
            $publicite = Publicite::find($id);

            if(!$publicite){
                return response()->json([
                    'success' => false,
                    'message' => 'Publicité introuvable'
                ],404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $publicite->id,
                    'image_url' => $publicite->image_url,
                    'created_at' => $publicite->created_at
                ],
                'message' => 'Publicité affichée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de la publicité',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function delete_publicite(Request $request, $id){
        try{
            $publicite = Publicite::find($id);

            if(!$publicite){
                return response()->json([
                    'success' => false,
                    'message' => 'Publicité introuvable'
                ],404);
            }

            $publicite->delete();

            return response()->json([
                'success' => true,
                'message' => 'Publicité supprimée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la publicité',
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

        $response = Http::asForm()->post('https://api.imgbb.com/1/upload', [
            'key' => $apiKey,
            'image' => $imageContent,
        ]);

        if ($response->successful()) {
            return $response->json()['data']['url'];
        }

        throw new \Exception("Erreur lors de l'envoi de l'image : " . $response->body());
    }
}
