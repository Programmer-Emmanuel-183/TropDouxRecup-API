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
            $marchand = $request->user();

            if (!$marchand) {
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand non authentifié',
                    'data' => null
                ], 401);
            }

            $marchandId = $marchand->id;

            /* =============================
             * PÉRIODE
             * ============================= */
            $startMonth = Carbon::now()->startOfMonth();
            $endMonth   = Carbon::now()->endOfMonth();

            /* =============================
             * REVENU DU MOIS
             * ============================= */
            $revenuMois = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->where('sous_commandes.id_marchand', $marchandId)
                ->where('sous_commandes.statut', 'complete')
                ->whereBetween('sous_commandes.created_at', [$startMonth, $endMonth])
                ->sum(DB::raw('plats.prix_reduit * sous_commandes.quantite_plat'));

            /* =============================
             * COMMANDES DU MOIS
             * ============================= */
            $commandesMois = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->whereBetween('created_at', [$startMonth, $endMonth])
                ->count();

            /* =============================
             * CLIENTS ACTIFS DU MOIS
             * ============================= */
            $clientsMois = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->whereBetween('created_at', [$startMonth, $endMonth])
                ->distinct('id_client')
                ->count('id_client');

            /* =============================
             * ÉCONOMIES CLIENTS (JAMAIS NULL)
             * ============================= */
            $economies_clients_mois = max(0, $clientsMois);

            /* =============================
             * DASHBOARD DATAS
             * ============================= */
            $dashboard_datas = [
                [
                    'id' => '1',
                    'type' => 'revenu',
                    'libelle' => 'Revenu du mois',
                    'value' => number_format($revenuMois, 0, ',', ' ') . ' FCFA',
                    'monthly_progress' => $revenuMois > 0 ? [
                        'evolution' => 0,
                        'evolution_value' => 0
                    ] : null
                ],
                [
                    'id' => '2',
                    'type' => 'commande',
                    'libelle' => 'Commandes du mois',
                    'value' => (string) $commandesMois,
                    'monthly_progress' => $commandesMois > 0 ? [
                        'evolution' => 0,
                        'evolution_value' => 0
                    ] : null
                ],
                [
                    'id' => '3',
                    'type' => 'client',
                    'libelle' => 'Clients actifs',
                    'value' => (string) $clientsMois,
                    'monthly_progress' => $clientsMois > 0 ? [
                        'evolution' => 0,
                        'evolution_value' => 0
                    ] : null
                ],
                [
                    'id' => '4',
                    'type' => 'recouvrement',
                    'libelle' => 'Taux de recouvrement',
                    'value' => $commandesMois > 0 ? '100%' : '0%',
                    'monthly_progress' => null
                ]
            ];

            /* =============================
             * STATISTICS DATAS (7 JOURS FR)
             * ============================= */
            $jours = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];
            $statistics_datas = [];

            foreach ($jours as $jour) {
                $statistics_datas[$jour] = [
                    'day' => $jour,
                    'revenu' => 0,
                    'progression_percent' => 0
                ];
            }

            $startWeek = Carbon::now()->startOfWeek(Carbon::MONDAY);
            $endWeek   = Carbon::now()->endOfWeek(Carbon::SUNDAY);

            $ventesSemaine = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->select(
                    DB::raw('DATE(sous_commandes.created_at) as date'),
                    DB::raw('SUM(plats.prix_reduit * sous_commandes.quantite_plat) as total')
                )
                ->where('sous_commandes.id_marchand', $marchandId)
                ->where('sous_commandes.statut', 'complete')
                ->whereBetween('sous_commandes.created_at', [$startWeek, $endWeek])
                ->groupBy(DB::raw('DATE(sous_commandes.created_at)'))
                ->get();

            foreach ($ventesSemaine as $vente) {
                $dayIndex = Carbon::parse($vente->date)->dayOfWeekIso; // 1 (lun) → 7 (dim)
                $jour = $jours[$dayIndex - 1];

                $statistics_datas[$jour]['revenu'] = (int) $vente->total;
            }

            $statistics_datas = array_values($statistics_datas);

            /* =============================
             * PIE DATAS
             * ============================= */
            $pie_datas = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->select(
                    'plats.nom',
                    DB::raw('SUM(sous_commandes.quantite_plat) as total')
                )
                ->where('sous_commandes.id_marchand', $marchandId)
                ->where('sous_commandes.statut', 'complete')
                ->whereBetween('sous_commandes.created_at', [$startMonth, $endMonth])
                ->groupBy('plats.nom')
                ->get();

            $pie_datas = $pie_datas->count() > 0 ? $pie_datas : null;

            /* =============================
             * RESPONSE
             * ============================= */
            return response()->json([
                'success' => true,
                'message' => 'Statistiques marchand',
                'data' => [
                    'dashboard_datas' => $dashboard_datas,
                    'statistics_datas' => $statistics_datas,
                    'pie_datas' => $pie_datas,
                    'economies_clients_mois' => $economies_clients_mois
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
