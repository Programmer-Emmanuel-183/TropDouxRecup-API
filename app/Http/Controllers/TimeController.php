<?php

namespace App\Http\Controllers;

use App\Models\Time;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TimeController extends Controller
{
    public function time(Request $request){
        try{
            $time = Time::first();
            if(!$time){
                return response()->json([
                    'success' => false,
                    'message' => 'Heure introuvabke'
                ],404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $time->id,
                    'time_disabled' => $time->time_disabled,
                    'time_enabled' => $time->time_enabled
                ],
                'message' => 'Heure affichée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage de l’heure activation et desactivation',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function update_time(Request $request){
        $validator = Validator::make($request->all(), [
            'time_disabled' => 'date_format:H:i|required',
            'time_enabled' => 'date_format:H:i|required',
        ]);
        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }
        try{
            $time = Time::first();
            if(!$time){
                return response()->json([
                    'success' => false,
                    'message' => 'Heure introuvabke'
                ],404);
            }

            $time->time_disabled = $request->time_disabled;
            $time->time_enabled = $request->time_enabled;
            $time->save();

             return response()->json([
                'success' => true,
                'data' => [
                    'id' => $time->id,
                    'time_disabled' => $time->time_disabled,
                    'time_enabled' => $time->time_enabled
                ],
                'message' => 'Heure modifiée avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification de l’heure d’activation et de desactivation',
                'erreur' => $e->getMessage()
            ],500);
        }
    }
}
