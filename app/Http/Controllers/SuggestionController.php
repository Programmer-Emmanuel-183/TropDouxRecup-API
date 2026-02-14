<?php

namespace App\Http\Controllers;

use App\Models\FavorisMarchand;
use App\Models\FavorisPlat;
use App\Models\Marchand;
use App\Models\Plat;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class SuggestionController extends Controller
{
    /**
     * 🔹 Suggestions pour l'autocomplete
     */
    public function results(Request $request)
    {
        try {
            $search = $request->query('search');

            if (!$search) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paramètre search manquant',
                ], 400);
            }

            // 🔍 Suggestions plats
            $plats = Plat::where('is_active', true)
                ->where(function ($query) use ($search) {
                    $query->where('nom_plat', 'LIKE', "%{$search}%")
                          ->orWhereHas('categorie', fn($q) => $q->where('nom_categorie', 'LIKE', "%{$search}%"))
                          ->orWhereHas('marchand', fn($q) => $q->where('nom_marchand', 'LIKE', "%{$search}%"));
                })
                ->limit(10)
                ->get(['id', 'nom_plat as libelle']);

            // 🔍 Suggestions marchands
            $marchands = Marchand::where('is_verify', true)
                ->where(function ($query) use ($search) {
                    $query->where('nom_marchand', 'LIKE', "%{$search}%")
                          ->orWhereHas('plats', fn($q) => $q->where('nom_plat', 'LIKE', "%{$search}%")
                                                             ->orWhereHas('categorie', fn($c) => $c->where('nom_categorie', 'LIKE', "%{$search}%")) );
                })
                ->limit(10)
                ->get(['id', 'nom_marchand as libelle']);

            return response()->json([
                'success' => true,
                'data' => array_merge(
                    $plats->toArray(),
                    $marchands->toArray()
                ),
                'message' => 'Suggestions récupérées avec succès',
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des suggestions',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * 🔹 Résultats complets de recherche
     */
    public function suggestions(Request $request)
    {
        try {
            $user = $request->user(); // client connecté ou null
            $userId = $user?->id ?? null;

            $search = $request->query('search');
            if (!$search) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paramètre search manquant',
                ], 400);
            }

            /** ===============================
             *  PLATS
             * =============================== */
            $plats = Plat::with(['marchand', 'categorie'])
                ->where('is_active', true)
                ->where(function ($query) use ($search) {
                    $query->where('nom_plat', 'LIKE', "%{$search}%")
                        ->orWhereHas('categorie', fn($q) => $q->where('nom_categorie', 'LIKE', "%{$search}%"))
                        ->orWhereHas('marchand', fn($q) => $q->where('nom_marchand', 'LIKE', "%{$search}%"));
                })
                ->limit(50)
                ->get()
                ->map(function ($plat) use ($userId) {

                    // Vérification favoris seulement si utilisateur connecté
                    $isFav = $userId
                        ? FavorisPlat::where('id_client', $userId)->where('id_plat', $plat->id)->exists()
                        : false;

                    return [
                        'id' => $plat->id,
                        'nom_plat' => $plat->nom_plat,
                        'description_plat' => $plat->description_plat,
                        'image_couverture' => $plat->image_couverture,
                        'prix_origine' => $plat->prix_origine,
                        'prix_reduit' => $plat->prix_reduit,
                        'quantite_disponible' => $plat->quantite_disponible,
                        'is_favorite' => $isFav,
                    ];
                });

            /** ===============================
             *  MARCHANDS
             * =============================== */
            $marchands = Marchand::with(['commune', 'plats'])
                ->where('is_verify', true)
                ->where(function ($query) use ($search) {
                    $query->where('nom_marchand', 'LIKE', "%{$search}%")
                        ->orWhereHas('plats', fn($q) => $q->where('nom_plat', 'LIKE', "%{$search}%")
                                                            ->orWhereHas('categorie', fn($c) => $c->where('nom_categorie', 'LIKE', "%{$search}%")) );
                })
                ->limit(50)
                ->get()
                ->map(function ($marchand) use ($userId) {

                    // Vérification favoris
                    $isFav = $userId
                        ? FavorisMarchand::where('id_client', $userId)->where('id_marchand', $marchand->id)->exists()
                        : false;

                    // Nombre de plats actifs
                    $platsActifs = $marchand->plats->where('is_active', true)->count();

                    // Pourcentage de plats actifs
                    $pourcentage = $marchand->plats->count() > 0
                        ? round(($platsActifs / $marchand->plats->count()) * 100) . '%'
                        : '0%';

                    // Étoiles (rating exemple)
                    $etoile = $marchand->etoile ?? 0;

                    return [
                        'id' => $marchand->id,
                        'nom' => $marchand->nom_marchand,
                        'localite' => $marchand->commune->localite ?? null,
                        'plat_restant' => $platsActifs,
                        'pourcentage' => $pourcentage,
                        'etoile_marchand' => $etoile,
                        'is_favorite' => $isFav,
                        'image' => $marchand->image_marchand,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'plat' => $plats,
                    'marchand' => $marchands,
                ],
                'message' => 'Résultats récupérés avec succès',
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}



