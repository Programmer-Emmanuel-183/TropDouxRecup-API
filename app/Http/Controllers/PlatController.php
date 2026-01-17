<?php

namespace App\Http\Controllers;

use App\Models\Avis;
use App\Models\Categorie;
use App\Models\Plat;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

use function Symfony\Component\Clock\now;

class PlatController extends Controller
{
    public function ajout_plat(Request $request){
        $validator = Validator::make($request->all(), [
            'nom_plat' => 'required',
            'description_plat' => 'required',
            'image_couverture' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'autre_image' => 'nullable|array|max:3',
            'autre_image.*' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'prix_origine' => 'required|numeric|min:1',
            'prix_reduit' => 'required|numeric|lt:prix_origine|min:0',
            'quantite_plat' => 'required|integer|min:1',
            'is_active' => 'nullable|boolean',
            'id_categorie' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $categorie = Categorie::find($request->id_categorie);
            if (!$categorie) {
                return response()->json(['success' => false, 'message' => 'Categorie non trouvée'], 404);
            }

            $user = $request->user();
            if (!$user || $user->adresse_marchand === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Veuillez renseigner une adresse marchand valide.'
                ], 400);
            }

            $imageCouverture = $this->uploadImageToHosting($request->file('image_couverture'));

            /**
             * 🔥 autre_image → on ne stocke QUE les images réelles
             */
            $autreImages = [];

            if ($request->hasFile('autre_image')) {
                foreach ($request->file('autre_image') as $file) {
                    if ($file && $file->isValid()) {
                        $autreImages[] = $this->uploadImageToHosting($file);
                    }
                }
            }

            $plat = Plat::create([
                'nom_plat' => $request->nom_plat,
                'description_plat' => $request->description_plat,
                'image_couverture' => $imageCouverture,
                'autre_image' => !empty($autreImages) ? $autreImages : null,
                'prix_origine' => $request->prix_origine,
                'prix_reduit' => $request->prix_reduit,
                'quantite_plat' => $request->quantite_plat,
                'quantite_disponible' => $request->quantite_plat,
                'is_active' => $request->is_active ?? true,
                'id_categorie' => $categorie->id,
                'id_marchand' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => $plat,
                'message' => 'Plat ajouté avec succès.'
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’ajout du plat',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }



    public function plat_marchand(Request $request){
        try {
            $user = $request->user();
            if(!$user){
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand non trouvé'
                ],404);
            }

            $statut = $request->query('statut');

            $query = Plat::where('id_marchand', $user->id)->orderBy('created_at', 'desc');

            if ($statut === 'actif') {
                $query->where('is_active', true);
            } elseif ($statut === 'inactif') {
                $query->where('is_active', false);
            }


            $plats = $query->get();

            $baseQuery = Plat::where('id_marchand', $user->id);

            if ($plats->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'external_data' => [
                        'nbre_total'   => $baseQuery->count(),
                        'nbre_actif'   => $baseQuery->clone()->where('is_active', 1)->count(),
                        'nbre_inactif' => $baseQuery->clone()->where('is_active', 0)->count(),
                        'nbre_restant' => $baseQuery->clone()->where('quantite_disponible', '>', 0)->count(),
                    ],
                    'message' => 'Aucun plat trouvé'
                ],200);
            }

            $data = $plats->map(function ($plat) use ($user) {
                $reduction = 0;
                if ($plat->prix_origine > 0 && $plat->prix_reduit !== null) {
                    $reduction = (($plat->prix_origine - $plat->prix_reduit) / $plat->prix_origine) * 100;
                }

                return [
                    'id' => $plat->id,
                    'nom_plat' => $plat->nom_plat,
                    'description_plat' => $plat->description_plat,
                    'image_couverture' => $plat->image_couverture,
                    'prix_origine' => $plat->prix_origine,
                    'prix_reduit' => $plat->prix_reduit,
                    'quantite_plat' => $plat->quantite_plat,
                    'quantite_disponible' => $plat->quantite_disponible,
                    'statut' => $plat->is_active ? 'actif' : 'inactif',
                    'reduction' => "-" . round($reduction, 2) . "%",
                    'marchand' => $user->nom_marchand
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'external_data' => [
                    'nbre_total'   => $baseQuery->count(),
                    'nbre_actif'   => $baseQuery->clone()->where('is_active', 1)->count(),
                    'nbre_inactif' => $baseQuery->clone()->where('is_active', 0)->count(),
                    'nbre_restant' => $baseQuery->clone()->where('quantite_disponible', '>', 0)->count(),
                ],  
                'message' => 'Liste des plats du marchand connecté.'
            ], 200);

        } catch(QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage des plats du marchand connecté',
                'erreur' => $e->getMessage()
            ],500);
        }
    }


    public function plats(Request $request){
        try {
            $begin = $request->query('begin');
            $end = $request->query('end');

            $query = Plat::with(['categorie', 'marchand'])
                ->where('is_active', true)->whereNot('quantite_disponible', 0);

            if (!is_null($begin) && !is_null($end)) {
                $query->whereBetween('prix_reduit', [(int)$begin, (int)$end]);
            }

            $plats = $query->get();

            if ($plats->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucun plat trouvé'
                ], 200);
            }

            $data = $plats->map(function ($plat) {
                return [
                    'id' => $plat->id,
                    'nom_plat' => $plat->nom_plat,
                    // 'description_plat' => $plat->description_plat,
                    'image_couverture' => $plat->image_couverture,
                    // 'autre_image' => $plat->autre_image,
                    'prix_origine' => $plat->prix_origine,
                    'prix_reduit' => $plat->prix_reduit,
                    'quantite_plat' => $plat->quantite_plat,
                    'quantite_disponible' => $plat->quantite_disponible,
                    'is_favorite' => true,
                    // 'is_active' => $plat->is_active,
                    // 'is_finish' => $plat->is_finish,
                    // 'categorie' => [
                        // 'nom_categorie' => $plat->categorie->nom_categorie ?? null,
                        // 'image_categorie' => $plat->categorie->image_categorie ?? null
                    // ],
                    'marchand' => $plat->marchand->nom_marchand ?? null
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Liste des plats.'
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage des plats',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }


    public function plat(Request $request, $id){
        try {
            $plat = Plat::find($id);
            $avis = Avis::where('id_plat', $plat->id)->avg('etoile') ?? 0;
            $moyenne = round($avis, 1);
            if (!$plat) {
                return response()->json([
                    'success' => false,
                    'message' => 'Plat non trouvé'
                ], 404);
            }

            // $recommandations = Plat::inRandomOrder()->limit(10)->get()->map(function ($item) {
            //     return [
            //         'id' => $item->id,
            //         'nom_plat' => $item->nom_plat,
            //         'image_couverture' => $item->image_couverture,
            //         'quantite_plat' => $item->quantite_plat,
            //         'quantite_disponible' => $item->quantite_disponible,
            //         'prix_origine' => $item->prix_origine,
            //         'prix_reduit' => $item->prix_reduit,
            //     ];
            // });

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $plat->id,
                    'nom_plat' => $plat->nom_plat,
                    'description_plat' => $plat->description_plat,
                    'image_couverture' => $plat->image_couverture,
                    'autre_image' => $plat->autre_image ?? [],
                    'prix_origine' => $plat->prix_origine,
                    'prix_reduit' => $plat->prix_reduit,
                    'quantite_plat' => $plat->quantite_plat,
                    'quantite_disponible' => $plat->quantite_disponible,
                    'statut' => $plat->is_active == 1 ? 'actif' : 'inactif',
                    'categorie' => $plat->categorie ? [
                        'id' => $plat->categorie->id,
                        'nom_categorie' => $plat->categorie->nom_categorie,
                        'image_categorie' => $plat->categorie->image_categorie
                    ] : null,
                    'etoile' => $moyenne,
                    // 'recommandation' => $recommandations
                ]
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage du plat',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }

    public function plat_recommande(Request $request){
        try{
            $recommandations = Plat::inRandomOrder()->limit(10)->get()->map(function ($item) {
                return [
                    'id' => $item->id,
                    'nom_plat' => $item->nom_plat,
                    'image_couverture' => $item->image_couverture,
                    'quantite_plat' => $item->quantite_plat,
                    // 'quantite_disponible' => $item->quantite_disponible,
                    'prix_origine' => $item->prix_origine,
                    'prix_reduit' => $item->prix_reduit,
                ];
            });

            if($recommandations->isEmpty()){
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucune drecommandations'
                ],200);
            }

            return response()->json([
                'success' => true,
                'data' => $recommandations,
                'message' => 'Liste des recommandations affichées avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage des recommandations',
                'erreur' => $e->getMessage()
            ],500);
        }
    }




    public function delete_plat(Request $request, $id){
        $user = $request->user();
        if(!$user){
            return response()->json([
                'success' => false,
                'message' => 'Marchand non trouvé'
            ],404);
        }

        try{
            $plat = Plat::find($id);
            if(!$plat){
                return response()->json([
                    'success' => false,
                    'message' => 'Plat non trouvé'
                ],404);
            }

            if($plat->id_marchand != $user->id){
                return response()->json([
                    'success' => true,
                    'message' => 'Ce plat n’appartient pas à ce marchand'
                ],403);
            }

            $plat->delete();
            return response()->json([
                'success' => true,
                'message' => 'Plat supprmé avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du plat',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function update_plat(Request $request, $id){
        /**
         * 🔥 Nettoyage "null" string → null réel
         */
        if ($request->has('autre_image')) {
            $raw = $request->all()['autre_image'];
            $clean = [];

            foreach ($raw as $index => $item) {
                $clean[$index] = $item === 'null' ? null : $item;
            }

            $request->merge(['autre_image' => $clean]);
        }

        // ✅ VALIDATION
        $validator = Validator::make($request->all(), [
            'nom_plat' => 'required|string',
            'description_plat' => 'required|string',
            'image_couverture' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'autre_image' => 'sometimes|array|max:3',
            'autre_image.*' => 'nullable',
            'prix_origine' => 'required|numeric|min:1',
            'prix_reduit' => 'required|numeric|lt:prix_origine|min:0',
            'quantite_plat' => 'required|integer|min:1',
            'quantite_disponible' => 'required|integer|min:1',
            'is_active' => 'nullable|boolean',
            'id_categorie' => 'required|exists:categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        if ($request->quantite_disponible > $request->quantite_plat) {
            return response()->json([
                'success' => false,
                'message' => 'La quantité disponible doit être ≤ à la quantité totale.'
            ], 400);
        }

        try {
            $plat = Plat::find($id);
            if (!$plat) {
                return response()->json([
                    'success' => false,
                    'message' => 'Plat non trouvé'
                ], 404);
            }

            /**
             * 🖼 Image couverture
             */
            $imageCouverture = $request->hasFile('image_couverture')
                ? $this->uploadImageToHosting($request->file('image_couverture'))
                : $plat->image_couverture;

            /**
             * 📦 DATA PRINCIPALE
             */
            $data = [
                'nom_plat' => $request->nom_plat,
                'description_plat' => $request->description_plat,
                'image_couverture' => $imageCouverture,
                'prix_origine' => $request->prix_origine,
                'prix_reduit' => $request->prix_reduit,
                'quantite_plat' => $request->quantite_plat,
                'quantite_disponible' => $request->quantite_disponible,
                'is_active' => $request->is_active ?? $plat->is_active,
                'id_categorie' => $request->id_categorie,
            ];

            /**
             * 🔥 autre_image — INDEX PILOTÉ PAR LE FRONT
             */
            if ($request->has('autre_image')) {

                $existing = $plat->autre_image ?? [];
                $finalImages = [];

                foreach ($request->autre_image as $index => $item) {

                    // 🔁 GARDER L’EXISTANTE
                    if ($item === 'same') {
                        if (isset($existing[$index])) {
                            $finalImages[$index] = $existing[$index];
                        }
                        continue;
                    }

                    // ❌ SUPPRIMER
                    if ($item === null) {
                        continue;
                    }

                    // 🖼 NOUVELLE IMAGE
                    if ($item instanceof \Illuminate\Http\UploadedFile && $item->isValid()) {
                        $finalImages[$index] = $this->uploadImageToHosting($item);
                    }
                }

                // 🔒 Réindexation propre pour stockage JSON
                ksort($finalImages);
                $data['autre_image'] = count($finalImages)
                    ? array_values($finalImages)
                    : null;
            }

            $plat->update($data);

            return response()->json([
                'success' => true,
                'data' => $plat->fresh(),
                'message' => 'Plat modifié avec succès.'
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }



    public function duplicate_plat(Request $request, $id){
        try{
            $plat = Plat::find($id);
            $marchand = $request->user();
            if(!$plat){
                return response()->json([
                    'success' => false,
                    'message' => 'Plat non trouvé'
                ],404);
            }
            if($plat->id_marchand != $marchand->id){
                return response()->json([
                    'success' => false,
                    'message' => 'Ce plat ne vous appartient pas ! Vous ne pouvez pas le dupliquer'
                ],403);
            }
            $duplicate = $plat->replicate();
            $duplicate->created_at = now();
            $duplicate->updated_at = now();
            $duplicate->save();
             return response()->json([
                'success' => true,
                'data' => [
                    'id' => $duplicate->id,
                    'nom_plat' => $duplicate->nom_plat,
                    'description_plat' => $duplicate->description_plat,
                    'image_couverture' => $duplicate->image_couverture,
                    'autre_image' => $duplicate->autre_image,
                    'prix_origine' => $duplicate->prix_origine,
                    'prix_reduit' => $duplicate->prix_reduit,
                    'quantite_plat' => $duplicate->quantite_plat,
                    'quantite_disponible' => $duplicate->quantite_disponible,
                    'is_active' => $duplicate->is_active,
                    'categorie' => [
                        'nom_categorie' => $duplicate->categorie->nom_categorie,
                        'image_categorie' => $duplicate->categorie->image_categorie
                    ],
                    'marchand' => $duplicate->marchand->nom_marchand
                ],
                'message' => 'Plat dupliqué avec succès'
             ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la duplication du plat',
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
