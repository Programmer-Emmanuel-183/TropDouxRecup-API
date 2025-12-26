<?php

namespace App\Http\Controllers;

use App\Models\Abonnement;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class AbonnementController extends Controller
{
    public function ajout_abonnement(Request $request){
        $validator = Validator::make($request->all(),[
            'type_abonnement' => 'required',
            'description' => 'required',
            'montant' => 'required',
            'duree' => 'required|in:mois,illimite,trimestre,semestre,annee,semaine',
            'icon_url' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'icon_bg_color' => 'required',
            'avantages' => 'required|array',
            'avantages.*' => 'uuid|exists:avantages,id'
        ]);

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $image = $this->uploadImageToHosting($request->file('icon_url'));

            $abonnement = new Abonnement();
            $abonnement->type_abonnement = $request->type_abonnement;
            $abonnement->description = $request->description;
            $abonnement->montant = $request->montant;
            $abonnement->duree = $request->duree;
            $abonnement->icon_url = $image;
            $abonnement->icon_bg_color = $request->icon_bg_color;
            $abonnement->save();

            $abonnement->avantages()->attach($request->avantages);

            $avantages = $abonnement->avantages()->select('avantages.id', 'avantages.nom_avantage', 'avantages.value')->get()->makeHidden('pivot');


            return response()->json([
                'success' => true,
                'data' => [
                    'abonnement' => [
                        'id' => $abonnement->id,
                        'type_abonnement' => $abonnement->type_abonnement,
                        'description' => $abonnement->description,
                        'montant' => $abonnement->montant,
                        'duree' => $abonnement->duree === 'illimite' ? null : $abonnement->duree,
                        'icon_url' => $abonnement->icon_url,
                        'icon_bg_color' => $abonnement->icon_bg_color
                    ],
                    'avantages' => $avantages
                    ],
                'message' => 'Abonnement créé avec succès',
            ]);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’ajout de l’abonnement',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function liste_abonnement(){
        try {
            // 🔹 Récupérer les abonnements avec le nombre d'utilisations
            $abonnements = Abonnement::select(
                    'abonnements.id',
                    'type_abonnement',
                    'description',
                    'montant',
                    'duree',
                    'icon_url',
                    'icon_bg_color',
                    'created_at'
                )
                ->withCount('marchands') // relation obligatoire
                ->with(['avantages:id,nom_avantage,value'])
                ->get();

            // 🔹 Trouver le max d'utilisation
            $maxUsage = $abonnements->max('marchands_count');

            $abonnements->each(function ($abonnement) use ($maxUsage) {

                // 🔥 is_popular dynamique
                $abonnement->is_popular = $abonnement->marchands_count === $maxUsage && $maxUsage > 0;

                // 🔹 Durée illimitée
                $abonnement->duree = $abonnement->duree === 'illimite' ? null : $abonnement->duree;

                // 🔹 Formatage avantages
                $abonnement->avantages->each(function ($avantage) {
                    $avantage->nom_avantage = trim($avantage->value . ' ' . $avantage->nom_avantage);
                    unset($avantage->value, $avantage->pivot);
                });

                // Optionnel : ne pas exposer le count
                unset($abonnement->marchands_count);
            });

            return response()->json([
                'success' => true,
                'data' => $abonnements,
                'message' => 'Liste des abonnements affichés avec succès.'
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Echec lors de l’affichage de la liste des abonnements',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }



    public function update_abonnement(Request $request, $id){
        $validator = Validator::make($request->all(),[
            'type_abonnement' => 'required',
            'description' => 'required',
            'montant' => 'required',
            'duree' => 'required|in:mois,illimite,trimestre,semestre,annee,semaine',
            'icon_url' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'icon_bg_color' => 'required',
            'avantages' => 'required|array',
            'avantages.*' => 'uuid|exists:avantages,id'
        ]);

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $abonnement = Abonnement::find($id);
            if(!$abonnement){
                return response()->json([
                    'success' => false,
                    'message' => 'Abonnement non trouvé'
                ],404);
            }
            $image = $this->uploadImageToHosting($request->file('icon_url'));

            $abonnement->type_abonnement = $request->type_abonnement;
            $abonnement->description = $request->description;
            $abonnement->montant = $request->montant;
            $abonnement->duree = $request->duree;
            $abonnement->icon_url = $image;
            $abonnement->icon_bg_color = $request->icon_bg_color;
            $abonnement->save();

            $abonnement->avantages()->sync($request->avantages);

            $avantages = $abonnement->avantages()->select('avantages.id', 'avantages.nom_avantage', 'avantages.value')->get()->makeHidden('pivot');
            

            return response()->json([
                'success' => true,
                'data' => [
                    'abonnement' => [
                        'id' => $abonnement->id,
                        'type_abonnement' => $abonnement->type_abonnement,
                        'description' => $abonnement->description,
                        'montant' => $abonnement->montant,
                        'duree' => $abonnement->duree,
                        'icon_url' => $abonnement->icon_url,
                        'icon_bg_color' => $abonnement->icon_bg_color
                    ],
                    'avantages' => $avantages
                    ],
                'message' => 'Abonnement modifié avec succès',
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification de l’abonnement',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function delete_abonnement($id){
        try{
            $abonnement = Abonnement::find($id);
            if(!$abonnement){
                return response()->json([
                    'success' => false,
                    'message' => 'Abonnement non trouvé'
                ],404);
            }

            $abonnement->delete();

            return response()->json([
                'success' => true,
                'message' => 'Abonnement supprimé avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => true,
                'message' => 'Erreur lors de la suppression de l’abonnenemt',
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
