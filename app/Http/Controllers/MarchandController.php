<?php

namespace App\Http\Controllers;

use App\Models\Marchand;
use App\Models\Plat;
use App\Models\SousCommande;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class MarchandController extends Controller
{
    public function marchand(Request $request, $id){
        try {
            $marchand = Marchand::find($id);

            if (!$marchand) {
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand non trouvé'
                ], 404);
            }

            $plats = Plat::where('id_marchand', $id)
                ->where('is_active', true)
                ->get();

            if ($plats->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => $marchand->id,
                        'nom_marchand' => $marchand->nom_marchand,
                        'localite' => $marchand->commune->localite ?? null,
                        'plat_restant' => 0,
                        'pourcentage' => 0,
                        // 'plats_dispo' => []
                    ],
                    'message' => 'Aucun plat disponible'
                ], 200);
            }

            $plats_dispo = $plats->map(function ($plat) {
                $pourcentage = $plat->prix_origine > 0 
                    ? round((($plat->prix_origine - $plat->prix_reduit) / $plat->prix_origine) * 100, 2)
                    : 0;

                return [
                    'nom_plat' => $plat->nom_plat,
                    'image_couverture' => $plat->image_couverture,
                    'quantite_disponible' => $plat->quantite_disponible,
                    'prix_origine' => $plat->prix_origine,
                    'prix_reduit' => $plat->prix_reduit,
                    'reduction_percent' => $pourcentage
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $marchand->id,
                    'nom_marchand' => $marchand->nom_marchand,
                    'localite' => $marchand->commune->localite ?? null,
                    'plat_restant' => $plats->count(),
                    'pourcentage' => $plats_dispo->avg('reduction_percent') . "%",
                    // 'plats_dispo' => $plats_dispo
                ],
                'message' => 'Informations du marchand'
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage du marchand',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }

    public function plat_disponible($id){
        try{
            $marchand = Marchand::find($id);

            if (!$marchand) {
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand non trouvé'
                ], 404);
            }

            $plats = Plat::where('id_marchand', $id)
                ->where('is_active', true)
                ->paginate(10);

            $data = $plats->getCollection()->map(function ($plat) {
                $pourcentage = $plat->prix_origine > 0 
                    ? round((($plat->prix_origine - $plat->prix_reduit) / $plat->prix_origine) * 100, 2)
                    : 0;

                return [
                    'nom_plat' => $plat->nom_plat,
                    'image_couverture' => $plat->image_couverture,
                    'quantite_disponible' => $plat->quantite_disponible,
                    'prix_origine' => $plat->prix_origine,
                    'prix_reduit' => $plat->prix_reduit,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'current_page' => $plats->currentPage(),
                'last_page' => $plats->lastPage(),
                'per_page' => $plats->perPage(),
                'total' => $plats->total(),
                'message' => 'Plats disponibles du marchand affichés avec succès'
            ],200);

        } catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur',
                'erreur' => $e->getMessage()
            ],500);
        }
    }


    public function general_info(Request $request){
        $marchand = $request->user();
        if(!$marchand){
            return response()->json([
                'success' => false,
                'message' => 'Marchand non trouvé'
            ],404);
        }

        try{
            $total_commande = SousCommande::where('id_marchand', $marchand->id)->count();
            $commande_attente = SousCommande::where('id_marchand', $marchand->id)->where('statut', 'pending')->count();
            $today_vente = SousCommande::where('id_marchand', $marchand->id)->whereDate('date_de_recuperation', today())->count();
            $plat_disponible = Plat::where('id_marchand', $marchand->id)->where('quantite_disponible', '>', 0)->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'today_vente' => $today_vente,
                    'total_commande' => $total_commande,
                    'commande_attente' => $commande_attente,
                    'plat_disponible' => $plat_disponible,
                    'solde' => $marchand->solde_marchand,
                    'type_abonnement' => $marchand->abonnement->type_abonnement
                ],
                'message' => 'Info general du marchand affichée avec succès.'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage des infos du marchand',
                'erreur' => $e->getMessage() 
            ],500);
        }
    }


    public function info_solde(Request $request){
        try{
            $user = $request->user();
            $marchand = Marchand::find($user->id);
             if(!$marchand || !$user){
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand non trouvé'
                ],404);
            }

            return response()->json([
                'success' => true,
                'data' => $marchand->solde_marchand,
                'message' => 'Solde du marchand affiché avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affaichage du solde du marchand',
                'erreur' => $e->getMessage()
            ],500);
        }
    }


}
