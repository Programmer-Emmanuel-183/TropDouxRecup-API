<?php

namespace App\Http\Controllers;

use App\Models\Marchand;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalytiqueController extends Controller
{
    public function analytique_marchand(Request $request){
        try {
            $marchand = $request->user(); // auth marchand
            $marchandId = $marchand->id;

            $now = Carbon::now();
            $startMonth = $now->copy()->startOfMonth();
            $endMonth   = $now->copy()->endOfMonth();

            /** ===============================
             *  REVENUS DU MOIS
             * =============================== */
            $revenuMois = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->where('sous_commandes.id_marchand', $marchandId)
                ->whereBetween('sous_commandes.created_at', [$startMonth, $endMonth])
                ->sum(DB::raw('plats.prix_reduit * sous_commandes.quantite_plat'));

            /** ===============================
             *  COMMANDES DU MOIS
             * =============================== */
            $commandesMois = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->whereBetween('created_at', [$startMonth, $endMonth])
                ->count();

            /** ===============================
             *  NOUVEAUX CLIENTS
             * =============================== */
            $clientsMois = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->whereBetween('created_at', [$startMonth, $endMonth])
                ->distinct('id_client')
                ->count('id_client');

            /** ===============================
             *  TAUX DE RECOUVREMENT
             * =============================== */
            $totalCommandes = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->count();

            $commandesLivrees = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->where('statut', 'livré')
                ->count();

            $tauxRecouvrement = $totalCommandes > 0
                ? round(($commandesLivrees / $totalCommandes) * 100)
                : 0;

            /** ===============================
             *  STATISTIQUES (7 DERNIERS JOURS)
             * =============================== */
            $stats = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->where('sous_commandes.id_marchand', $marchandId)
                ->where('sous_commandes.created_at', '>=', now()->subDays(6))
                ->select(
                    DB::raw('DATE(sous_commandes.created_at) as day'),
                    DB::raw('SUM(plats.prix_reduit * sous_commandes.quantite_plat) as revenu')
                )
                ->groupBy(DB::raw('DATE(sous_commandes.created_at)'))
                ->orderBy('day')
                ->get();

            $maxRevenu = $stats->max('revenu') ?: 1;

            $statisticsDatas = $stats->map(function ($item, $index) use ($maxRevenu) {
                return [
                    'id' => (string) ($index + 1),
                    'day' => Carbon::parse($item->day)->translatedFormat('D'),
                    'revenu' => (int) $item->revenu,
                    'progression_percent' => round(($item->revenu / $maxRevenu) * 100),
                ];
            });

            /** ===============================
             *  PIE DATA
             * =============================== */
            $plats = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->where('sous_commandes.id_marchand', $marchandId)
                ->select(
                    'plats.nom_plat',
                    DB::raw('SUM(sous_commandes.quantite_plat) as total')
                )
                ->groupBy('plats.nom_plat')
                ->orderByDesc('total')
                ->limit(5)
                ->get();

            $totalQuantite = $plats->sum('total') ?: 1;

            $pieDatas = $plats->map(function ($item, $index) use ($totalQuantite) {
                return [
                    'id' => (string) ($index + 1),
                    'nom_plat' => $item->nom_plat,
                    'percent_value' => round(($item->total / $totalQuantite) * 100),
                ];
            });

            $bestDay = $stats->sortByDesc('revenu')->first();

            /** ===============================
             *  DASHBOARD (TOUJOURS DISPONIBLE)
             * =============================== */
            $responseData = [
                'dashboard_datas' => [
                    [
                        'id' => '1',
                        'type' => 'revenu',
                        'libelle' => 'Revenu du mois',
                        'value' => number_format($revenuMois, 0, ',', ' ') . ' FCFA',
                        'monthly_progress' => null,
                    ],
                    [
                        'id' => '2',
                        'type' => 'commande',
                        'libelle' => 'Commandes du mois',
                        'value' => (string) $commandesMois,
                        'monthly_progress' => null,
                    ],
                    [
                        'id' => '3',
                        'type' => 'client',
                        'libelle' => 'Nouveaux clients',
                        'value' => (string) $clientsMois,
                        'monthly_progress' => null,
                    ],
                    [
                        'id' => '4',
                        'type' => 'recouvrement',
                        'libelle' => 'Taux de recouvrement',
                        'value' => $tauxRecouvrement . '%',
                        'monthly_progress' => null,
                    ],
                ],
            ];

            /** ===============================
             *  LOGIQUE PAR AVANTAGES
             * =============================== */
            $avantages = $marchand->abonnement?->avantages ?? collect();

            $hasStatsBase = $avantages->contains('nom_avantage', 'Statistiques de base');
            $hasStatsAdvanced = $avantages->contains('nom_avantage', 'Statistiques avancées');
            $hasDashboardFull = $avantages->contains('nom_avantage', 'Tableau de bord personnalisé');

            if ($hasStatsAdvanced || $hasDashboardFull) {
                $responseData['statistics_datas'] = $statisticsDatas;
            }

            if ($hasDashboardFull) {
                $responseData['pie_datas'] = $pieDatas;
                $responseData['economies_clients_mois'] = 0;
                $responseData['best_day'] = $bestDay ? [
                    'day' => Carbon::parse($bestDay->day)->translatedFormat('l'),
                    'revenu' => (int) $bestDay->revenu,
                    'order_count' => $commandesMois,
                ] : null;
            }

            return response()->json([
                'success' => true,
                'message' => 'Données analytiques récupérées avec succès',
                'data' => $responseData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des données analytiques',
                'erreur' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }



}
