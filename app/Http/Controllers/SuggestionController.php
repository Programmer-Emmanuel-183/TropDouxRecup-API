<?php

namespace App\Http\Controllers;

use App\Models\Marchand;
use App\Models\Plat;
use Illuminate\Http\Request;

class SuggestionController extends Controller
{
    public function search(Request $request){
        try {

            $search = $request->query('search');

            if (!$search) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paramètre search manquant',
                ], 400);
            }

            /**
             * 🔍 PLATS (par nom plat OU catégorie OU marchand)
             */
            $plats = Plat::with(['marchand', 'categorie'])
                ->where('is_active', true)
                ->where(function ($query) use ($search) {

                    // nom du plat
                    $query->where('nom_plat', 'LIKE', "%{$search}%")

                    // catégorie
                    ->orWhereHas('categorie', function ($q) use ($search) {
                        $q->where('nom_categorie', 'LIKE', "%{$search}%");
                    })

                    // marchand
                    ->orWhereHas('marchand', function ($q) use ($search) {
                        $q->where('nom_marchand', 'LIKE', "%{$search}%");
                    });
                })
                ->limit(20)
                ->get()
                ->map(function ($plat) {
                    return [
                        'id' => $plat->id,
                        'nom' => $plat->nom_plat,
                        'image' => $plat->image_couverture,
                        'prix' => $plat->prix_reduit,
                        'categorie' => $plat->categorie->nom_categorie ?? null,
                        'marchand' => $plat->marchand->nom_marchand ?? null,
                    ];
                });

            /**
             * 🔍 MARCHANDS
             * - par nom marchand
             * - OU marchands qui vendent des plats correspondant à la recherche
             */
            $marchands = Marchand::where('is_verify', true)
                ->where(function ($query) use ($search) {

                    // nom du marchand
                    $query->where('nom_marchand', 'LIKE', "%{$search}%")

                    // marchands ayant des plats correspondant
                    ->orWhereHas('plats', function ($q) use ($search) {
                        $q->where('nom_plat', 'LIKE', "%{$search}%")
                        ->orWhereHas('categorie', function ($c) use ($search) {
                            $c->where('nom_categorie', 'LIKE', "%{$search}%");
                        });
                    });
                })
                ->limit(20)
                ->get()
                ->map(function ($marchand) {
                    return [
                        'id' => $marchand->id,
                        'nom' => $marchand->nom_marchand,
                        'image' => $marchand->image_marchand,
                        'localite' => $marchand->commune->localite ?? null,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'plat' => $plats,
                    'marchand' => $marchands,
                ],
                'message' => 'Suggestions récupérées avec succès',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la recherche',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

}
