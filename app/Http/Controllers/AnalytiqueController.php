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
            $marchand = $request->user(); // marchand connecté
            $marchandId = $marchand->id;

            $now = Carbon::now();
            $startMonth = $now->copy()->startOfMonth();
            $endMonth   = $now->copy()->endOfMonth();

            /** ===============================
             *  REVENUS DU MOIS (APRÈS COMMISSION)
             * =============================== */
            // On récupère toutes les commandes du marchand ce mois
            $commandes = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->where('sous_commandes.id_marchand', $marchandId)
                ->whereBetween('sous_commandes.created_at', [$startMonth, $endMonth])
                ->select(
                    'sous_commandes.code_commande',
                    DB::raw('SUM(plats.prix_reduit * sous_commandes.quantite_plat) as total_commande'),
                    'sous_commandes.commission'
                )
                ->groupBy('sous_commandes.code_commande', 'sous_commandes.commission')
                ->get();

            // Calcul du revenu après application de la commission
            $revenuMois = $commandes->sum(function ($commande) {
                $commission = $commande->commission ?? 0; // commission en pourcentage
                return $commande->total_commande * (1 - $commission / 100);
            });

            /** ===============================
             *  COMMANDES DU MOIS
             * =============================== */
            $commandesMois = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->whereBetween('created_at', [$startMonth, $endMonth])
                ->distinct('code_commande')
                ->count('code_commande');

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
                ->distinct('code_commande')
                ->count('code_commande');

            $commandesLivrees = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->where('statut', 'livré')
                ->distinct('code_commande')
                ->count('code_commande');

            $tauxRecouvrement = $totalCommandes > 0
                ? round(($commandesLivrees / $totalCommandes) * 100)
                : 0;

            /** ===============================
             *  STATISTIQUES (7 DERNIERS JOURS)
             * =============================== */
            $statsRaw = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->where('sous_commandes.id_marchand', $marchandId)
                ->where('sous_commandes.created_at', '>=', now()->subDays(6))
                ->select(
                    'sous_commandes.code_commande',
                    'sous_commandes.created_at',
                    'sous_commandes.commission',
                    DB::raw('SUM(plats.prix_reduit * sous_commandes.quantite_plat) as total_commande')
                )
                ->groupBy('sous_commandes.code_commande', 'sous_commandes.created_at', 'sous_commandes.commission')
                ->get();

            // On regroupe par jour et applique la commission
            $statsGrouped = $statsRaw->groupBy(function ($item) {
                return Carbon::parse($item->created_at)->format('Y-m-d');
            });

            $stats = collect();
            foreach ($statsGrouped as $day => $commandesDay) {
                $revenuJour = $commandesDay->sum(function ($commande) {
                    $commission = $commande->commission ?? 0;
                    return $commande->total_commande * (1 - $commission / 100);
                });
                $stats->push((object)[
                    'day' => $day,
                    'revenu' => $revenuJour
                ]);
            }

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
