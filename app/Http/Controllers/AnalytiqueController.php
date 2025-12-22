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
                    'message' => 'Marchand non trouvé',
                    'data' => null
                ], 404);
            }

            $marchandId = $marchand->id;

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
            * COMMANDES & CLIENTS
            * ============================= */
            $commandesMois = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->whereBetween('created_at', [$startMonth, $endMonth])
                ->count();

            $clientsMois = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->whereBetween('created_at', [$startMonth, $endMonth])
                ->distinct('id_client')
                ->count('id_client');

            /* =============================
            * DASHBOARD (CONTRAT RESPECTÉ)
            * ============================= */
            $dashboard_datas = [
                [
                    'id' => '1',
                    'type' => 'revenu',
                    'libelle' => 'Revenu du mois',
                    'value' => number_format($revenuMois, 0, ',', ' ') . ' FCFA',
                    'monthly_progress' => [
                        'evolution' => 0,
                        'evolution_value' => 0
                    ]
                ],
                [
                    'id' => '2',
                    'type' => 'commande',
                    'libelle' => 'Commandes du mois',
                    'value' => (string) $commandesMois,
                    'monthly_progress' => [
                        'evolution' => 0,
                        'evolution_value' => 0
                    ]
                ],
                [
                    'id' => '3',
                    'type' => 'client',
                    'libelle' => 'Clients actifs',
                    'value' => (string) $clientsMois,
                    'monthly_progress' => [
                        'evolution' => 0,
                        'evolution_value' => 0
                    ]
                ],
                [
                    'id' => '4',
                    'type' => 'recouvrement',
                    'libelle' => 'Taux de recouvrement',
                    'value' => $commandesMois > 0 ? '100%' : '0%',
                    'monthly_progress' => [
                        'evolution' => 0,
                        'evolution_value' => 0
                    ]
                ]
            ];

            /* =============================
            * STATISTICS — 7 JOURS SEMAINE
            * ============================= */
            $startWeek = Carbon::now()->startOfWeek();
            $endWeek   = Carbon::now()->endOfWeek();

            $revenusParJour = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->selectRaw('
                    DATE(sous_commandes.created_at) as day,
                    SUM(plats.prix_reduit * sous_commandes.quantite_plat) as revenu
                ')
                ->where('sous_commandes.id_marchand', $marchandId)
                ->where('sous_commandes.statut', 'complete')
                ->whereBetween('sous_commandes.created_at', [$startWeek, $endWeek])
                ->groupBy('day')
                ->get()
                ->keyBy('day');

            $statistics_datas = [];
            $dayIndex = 1;

            for ($date = $startWeek->copy(); $date <= $endWeek; $date->addDay()) {
                $dayKey = $date->toDateString();

                $statistics_datas[] = [
                    'id' => (string) $dayIndex++,
                    'day' => $date->translatedFormat('D'),
                    'revenu' => isset($revenusParJour[$dayKey])
                        ? (int) $revenusParJour[$dayKey]->revenu
                        : 0,
                    'progression_percent' => 0
                ];
            }

            /* =============================
            * PIE — PLATS LES + VENDUS
            * ============================= */
            $totalQuantite = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->where('statut', 'complete')
                ->sum('quantite_plat');

            if ($totalQuantite > 0) {
                $pie_datas = DB::table('sous_commandes')
                    ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                    ->select(
                        'plats.nom_plat',
                        DB::raw('SUM(sous_commandes.quantite_plat) as total')
                    )
                    ->where('sous_commandes.id_marchand', $marchandId)
                    ->where('sous_commandes.statut', 'complete')
                    ->groupBy('plats.nom_plat')
                    ->orderByDesc('total')
                    ->limit(5)
                    ->get()
                    ->map(function ($item, $index) use ($totalQuantite) {
                        return [
                            'id' => (string) ($index + 1),
                            'nom_plat' => $item->nom_plat,
                            'percent_value' => round(($item->total / $totalQuantite) * 100)
                        ];
                    });
            } else {
                $pie_datas = null;
            }

            /* =============================
            * MEILLEUR JOUR
            * ============================= */
            $bestDay = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->selectRaw('
                    DATE(sous_commandes.created_at) as day,
                    COUNT(*) as order_count,
                    SUM(plats.prix_reduit * sous_commandes.quantite_plat) as revenu
                ')
                ->where('sous_commandes.id_marchand', $marchandId)
                ->where('sous_commandes.statut', 'complete')
                ->groupBy('day')
                ->orderByDesc('revenu')
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Analytique du marchand récupérée avec succès',
                'data' => [
                    'dashboard_datas' => $dashboard_datas,
                    'statistics_datas' => $statistics_datas,
                    'pie_datas' => $pie_datas,
                    'economies_clients_mois' => null,
                    'best_day' => $bestDay ? [
                        'day' => Carbon::parse($bestDay->day)->translatedFormat('l'),
                        'revenu' => (int) $bestDay->revenu,
                        'order_count' => (int) $bestDay->order_count
                    ] : null
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’analytique du marchand',
                'data' => null,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
