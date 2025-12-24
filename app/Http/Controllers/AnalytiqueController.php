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

            Carbon::setLocale('fr');

            $startMonth = Carbon::now()->startOfMonth();
            $endMonth = Carbon::now()->endOfMonth();

            $startPrevMonth = Carbon::now()->subMonth()->startOfMonth();
            $endPrevMonth = Carbon::now()->subMonth()->endOfMonth();

            $revenuMois = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->where('sous_commandes.id_marchand', $marchandId)
                ->where('sous_commandes.statut', 'complete')
                ->whereBetween('sous_commandes.created_at', [$startMonth, $endMonth])
                ->sum(DB::raw('plats.prix_reduit * sous_commandes.quantite_plat'));

            $revenuMoisPrecedent = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->where('sous_commandes.id_marchand', $marchandId)
                ->where('sous_commandes.statut', 'complete')
                ->whereBetween('sous_commandes.created_at', [$startPrevMonth, $endPrevMonth])
                ->sum(DB::raw('plats.prix_reduit * sous_commandes.quantite_plat'));

            $commandesMois = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->where('statut', 'complete')
                ->whereBetween('created_at', [$startMonth, $endMonth])
                ->count();

            $commandesMoisPrecedent = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->where('statut', 'complete')
                ->whereBetween('created_at', [$startPrevMonth, $endPrevMonth])
                ->count();

            $clientsMois = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->where('statut', 'complete')
                ->whereBetween('created_at', [$startMonth, $endMonth])
                ->distinct('id_client')
                ->count('id_client');

            $economiesClientsMois = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->where('sous_commandes.id_marchand', $marchandId)
                ->where('sous_commandes.statut', 'complete')
                ->whereBetween('sous_commandes.created_at', [$startMonth, $endMonth])
                ->sum(DB::raw('
                    (plats.prix_origine - plats.prix_reduit) * sous_commandes.quantite_plat
                '));

            $clientsMoisPrecedent = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->where('statut', 'complete')
                ->whereBetween('created_at', [$startPrevMonth, $endPrevMonth])
                ->distinct('id_client')
                ->count('id_client');

            $calcProgress = function ($current, $previous) {
                if ($previous <= 0) {
                    return null;
                }

                $diff = $current - $previous;
                return [
                    'evolution' => $diff >= 0 ? 1 : 0,
                    'evolution_value' => round(abs($diff / $previous) * 100, 1)
                ];
            };

            $dashboard_datas = [
                [
                    'id' => '1',
                    'type' => 'revenu',
                    'libelle' => 'Revenu du mois',
                    'value' => number_format($revenuMois, 0, ',', ' ') . ' FCFA',
                    'monthly_progress' => $calcProgress($revenuMois, $revenuMoisPrecedent)
                ],
                [
                    'id' => '2',
                    'type' => 'commande',
                    'libelle' => 'Commandes du mois',
                    'value' => (string) $commandesMois,
                    'monthly_progress' => $calcProgress($commandesMois, $commandesMoisPrecedent)
                ],
                [
                    'id' => '3',
                    'type' => 'client',
                    'libelle' => 'Nouveaux clients',
                    'value' => (string) $clientsMois,
                    'monthly_progress' => $calcProgress($clientsMois, $clientsMoisPrecedent)
                ],
                [
                    'id' => '4',
                    'type' => 'recouvrement',
                    'libelle' => 'Taux de recouvrement',
                    'value' => $commandesMois > 0 ? '100%' : '0%',
                    'monthly_progress' => null
                ]
            ];

            $startWeek = Carbon::now()->startOfWeek(Carbon::MONDAY);
            $endWeek = Carbon::now()->endOfWeek(Carbon::SUNDAY);

            $weekRevenus = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->selectRaw("
                    DAYOFWEEK(sous_commandes.created_at) as dow,
                    SUM(plats.prix_reduit * sous_commandes.quantite_plat) as revenu
                ")
                ->where('sous_commandes.id_marchand', $marchandId)
                ->where('sous_commandes.statut', 'complete')
                ->whereBetween('sous_commandes.created_at', [$startWeek, $endWeek])
                ->groupBy(DB::raw('DAYOFWEEK(sous_commandes.created_at)'))
                ->get();

            $jours = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'];

            $maxRevenu = $weekRevenus->max('revenu') ?: 1;

            $statistics_datas = collect($jours)->map(function ($jour, $index) use ($weekRevenus, $maxRevenu) {
                $dow = $index + 1;
                $revenu = (int) ($weekRevenus->firstWhere('dow', $dow)->revenu ?? 0);

                return [
                    'id' => (string) ($index + 1),
                    'day' => $jour,
                    'revenu' => $revenu,
                    'progression_percent' => round(($revenu / $maxRevenu) * 100)
                ];
            });

            $totalQuantite = DB::table('sous_commandes')
                ->where('id_marchand', $marchandId)
                ->where('statut', 'complete')
                ->sum('quantite_plat');

            $pie_datas = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->select('plats.nom_plat', DB::raw('SUM(sous_commandes.quantite_plat) as total'))
                ->where('sous_commandes.id_marchand', $marchandId)
                ->where('sous_commandes.statut', 'complete')
                ->groupBy('plats.id', 'plats.nom_plat')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(function ($item, $index) use ($totalQuantite) {
                    return [
                        'id' => (string) ($index + 1),
                        'nom_plat' => $item->nom_plat,
                        'percent_value' => $totalQuantite > 0
                            ? round(($item->total / $totalQuantite) * 100)
                            : 0
                    ];
                });

            $bestDay = DB::table('sous_commandes')
                ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
                ->selectRaw("
                    DATE(sous_commandes.created_at) as day,
                    COUNT(*) as order_count,
                    SUM(plats.prix_reduit * sous_commandes.quantite_plat) as revenu
                ")
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
                    'statistics_datas' => $statistics_datas->values(),
                    'pie_datas' => $pie_datas,
                    'economies_clients_mois' => (int) $economiesClientsMois,
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

    //------------------
    //--- CODE MYSQL ---
    //------------------
    // public function analytique_marchand(Request $request){
    //     try {
    //         $marchand = $request->user();

    //         if (!$marchand) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Marchand non trouvé',
    //                 'data' => null
    //             ], 404);
    //         }

    //         $marchandId = $marchand->id;

    //         Carbon::setLocale('fr');

    //         $startMonth = Carbon::now()->startOfMonth();
    //         $endMonth = Carbon::now()->endOfMonth();

    //         $startPrevMonth = Carbon::now()->subMonth()->startOfMonth();
    //         $endPrevMonth = Carbon::now()->subMonth()->endOfMonth();

    //         $revenuMois = DB::table('sous_commandes')
    //             ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
    //             ->where('sous_commandes.id_marchand', $marchandId)
    //             ->where('sous_commandes.statut', 'complete')
    //             ->whereBetween('sous_commandes.created_at', [$startMonth, $endMonth])
    //             ->sum(DB::raw('plats.prix_reduit * sous_commandes.quantite_plat'));

    //         $revenuMoisPrecedent = DB::table('sous_commandes')
    //             ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
    //             ->where('sous_commandes.id_marchand', $marchandId)
    //             ->where('sous_commandes.statut', 'complete')
    //             ->whereBetween('sous_commandes.created_at', [$startPrevMonth, $endPrevMonth])
    //             ->sum(DB::raw('plats.prix_reduit * sous_commandes.quantite_plat'));

    //         $commandesMois = DB::table('sous_commandes')
    //             ->where('id_marchand', $marchandId)
    //             ->whereBetween('created_at', [$startMonth, $endMonth])
    //             ->count();

    //         $commandesMoisPrecedent = DB::table('sous_commandes')
    //             ->where('id_marchand', $marchandId)
    //             ->whereBetween('created_at', [$startPrevMonth, $endPrevMonth])
    //             ->count();

    //         $clientsMois = DB::table('sous_commandes')
    //             ->where('id_marchand', $marchandId)
    //             ->whereBetween('created_at', [$startMonth, $endMonth])
    //             ->distinct('id_client')
    //             ->count('id_client');

    //         $clientsMoisPrecedent = DB::table('sous_commandes')
    //             ->where('id_marchand', $marchandId)
    //             ->whereBetween('created_at', [$startPrevMonth, $endPrevMonth])
    //             ->distinct('id_client')
    //             ->count('id_client');

    //         $economiesClientsMois = DB::table('sous_commandes')
    //             ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
    //             ->where('sous_commandes.id_marchand', $marchandId)
    //             ->where('sous_commandes.statut', 'complete')
    //             ->whereBetween('sous_commandes.created_at', [$startMonth, $endMonth])
    //             ->sum(DB::raw('
    //                 (plats.prix_origine - plats.prix_reduit) * sous_commandes.quantite_plat
    //             '));

    //         $progress = function ($current, $previous) {
    //             if ($previous <= 0) {
    //                 return null;
    //             }

    //             $diff = $current - $previous;

    //             return [
    //                 'evolution' => $diff >= 0 ? 1 : 0,
    //                 'evolution_value' => round(abs($diff / $previous) * 100, 1)
    //             ];
    //         };

    //         $dashboard_datas = [
    //             [
    //                 'id' => '1',
    //                 'type' => 'revenu',
    //                 'libelle' => 'Revenu du mois',
    //                 'value' => number_format($revenuMois, 0, ',', ' ') . ' FCFA',
    //                 'monthly_progress' => $progress($revenuMois, $revenuMoisPrecedent)
    //             ],
    //             [
    //                 'id' => '2',
    //                 'type' => 'commande',
    //                 'libelle' => 'Commandes du mois',
    //                 'value' => (string) $commandesMois,
    //                 'monthly_progress' => $progress($commandesMois, $commandesMoisPrecedent)
    //             ],
    //             [
    //                 'id' => '3',
    //                 'type' => 'client',
    //                 'libelle' => 'Nouveaux clients',
    //                 'value' => (string) $clientsMois,
    //                 'monthly_progress' => $progress($clientsMois, $clientsMoisPrecedent)
    //             ],
    //             [
    //                 'id' => '4',
    //                 'type' => 'recouvrement',
    //                 'libelle' => 'Taux de recouvrement',
    //                 'value' => $commandesMois > 0 ? '100%' : '0%',
    //                 'monthly_progress' => null
    //             ]
    //         ];

    //         $startWeek = Carbon::now()->startOfWeek(Carbon::MONDAY);
    //         $endWeek = Carbon::now()->endOfWeek(Carbon::SUNDAY);

    //         $weekRevenus = DB::table('sous_commandes')
    //             ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
    //             ->selectRaw("
    //                 DAYOFWEEK(sous_commandes.created_at) as dow,
    //                 SUM(plats.prix_reduit * sous_commandes.quantite_plat) as revenu
    //             ")
    //             ->where('sous_commandes.id_marchand', $marchandId)
    //             ->where('sous_commandes.statut', 'complete')
    //             ->whereBetween('sous_commandes.created_at', [$startWeek, $endWeek])
    //             ->groupBy('dow')
    //             ->get();

    //         $jours = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];

    //         $maxRevenu = $weekRevenus->max('revenu') ?: 1;

    //         $statistics_datas = collect($jours)->map(function ($jour, $index) use ($weekRevenus, $maxRevenu) {
    //             $mysqlDow = $index === 6 ? 1 : $index + 2;
    //             $revenu = (int) ($weekRevenus->firstWhere('dow', $mysqlDow)->revenu ?? 0);

    //             return [
    //                 'id' => (string) ($index + 1),
    //                 'day' => $jour,
    //                 'revenu' => $revenu,
    //                 'progression_percent' => round(($revenu / $maxRevenu) * 100)
    //             ];
    //         });

    //         $totalQuantite = DB::table('sous_commandes')
    //             ->where('id_marchand', $marchandId)
    //             ->sum('quantite_plat');

    //         $pie_datas = DB::table('sous_commandes')
    //             ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
    //             ->select('plats.nom_plat', DB::raw('SUM(sous_commandes.quantite_plat) as total'))
    //             ->where('sous_commandes.id_marchand', $marchandId)
    //             ->groupBy('plats.nom_plat')
    //             ->orderByDesc('total')
    //             ->limit(5)
    //             ->get()
    //             ->map(function ($item, $index) use ($totalQuantite) {
    //                 return [
    //                     'id' => (string) ($index + 1),
    //                     'nom_plat' => $item->nom_plat,
    //                     'percent_value' => $totalQuantite > 0
    //                         ? round(($item->total / $totalQuantite) * 100)
    //                         : 0
    //                 ];
    //             });

    //         $bestDay = DB::table('sous_commandes')
    //             ->join('plats', 'plats.id', '=', 'sous_commandes.id_plat')
    //             ->selectRaw("
    //                 DATE(sous_commandes.created_at) as day,
    //                 COUNT(*) as order_count,
    //                 SUM(plats.prix_reduit * sous_commandes.quantite_plat) as revenu
    //             ")
    //             ->where('sous_commandes.id_marchand', $marchandId)
    //             ->where('sous_commandes.statut', 'complete')
    //             ->groupBy('day')
    //             ->orderByDesc('revenu')
    //             ->first();

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Analytique du marchand récupérée avec succès',
    //             'data' => [
    //                 'dashboard_datas' => $dashboard_datas,
    //                 'statistics_datas' => $statistics_datas->values(),
    //                 'pie_datas' => $pie_datas,
    //                 'economies_clients_mois' => $economiesClientsMois,
    //                 'best_day' => $bestDay ? [
    //                     'day' => Carbon::parse($bestDay->day)->translatedFormat('l'),
    //                     'revenu' => (int) $bestDay->revenu,
    //                     'order_count' => (int) $bestDay->order_count
    //                 ] : null
    //             ]
    //         ], 200);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Erreur lors de l’analytique du marchand',
    //             'data' => null,
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

}
