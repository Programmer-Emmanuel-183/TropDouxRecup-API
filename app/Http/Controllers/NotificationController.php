<?php

namespace App\Http\Controllers;

use App\Models\Marchand;
use App\Models\Notification;
use App\Models\User;
use GuzzleHttp\Psr7\Query;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    public function update_device_token(Request $request){
        $validator = Validator::make($request->all(), [
            'device_token' => 'required'
        ]);
        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }

        try{
            $user = $request->user();
            $marchand = Marchand::find($user->id);
            $client = User::find($user->id);

            if($marchand){
                $marchand->device_token = $request->device_token;
                $marchand->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Device Token du marchand mis à jour avec succès'
                ],200);
            }

            if($client){
                $client->device_token = $request->device_token;
                $client->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Device Token du client mis à jour avec succès'
                ],200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non trouvé'
            ],404);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification du device token de l’utilisateur connecté',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function envoyer_notification_client(Request $request, $device_token){
        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'body' => 'required',
        ]);

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }
        try{
            $client = User::where('device_token', $device_token)->first();
            if(!$client){
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable'
                ], 404);
            }

            $notification = new Notification();
            $notification->type = 'Promotion'; 
            $notification->title = $request->title;
            $notification->body = $request->body;
            $notification->role = 'client';
            $notification->id_user = $client->id;
            $notification->save();

            app(PushNotifController::class)->sendPush($notification);

            return response()->json([
                'success' => true,
                'message' => 'Notification envoyé avec succès au client'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’envoi de notification à un client',
                'erreur' => $e->getMessage() 
            ],500);
        }
    }

    public function envoyer_notification_marchand(Request $request, $device_token){
        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'body' => 'required',
        ]);

        if($validator->fails()){
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ],422);
        }
        try{
            $marchand = Marchand::where('device_token', $device_token)->first();
            if(!$marchand){
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable'
                ], 404);
            }

            $notification = new Notification();
            $notification->type = 'Promotion'; 
            $notification->title = $request->title;
            $notification->body = $request->body;
            $notification->role = 'marchand';
            $notification->id_user = $marchand->id;
            $notification->save();

            app(PushNotifController::class)->sendPush($notification);

            return response()->json([
                'success' => true,
                'message' => 'Notification envoyé avec succès au marchand'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’envoi de notification à un marchand',
                'erreur' => $e->getMessage() 
            ],500);
        }
    }

    public function envoyer_notification_tous_clients(Request $request){
        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'body' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $clients = User::whereNotNull('device_token')->get();

            if ($clients->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun client avec device_token'
                ], 404);
            }
            $notifications = [];
            foreach ($clients as $client) {
                $notifications[] = Notification::create([
                    'type' => 'Promotion',
                    'title' => $request->title,
                    'body' => $request->body,
                    'role' => 'client',
                    'id_user' => $client->id,
                ]);
            }
            app(PushNotifController::class)->sendPushBatch($notifications);

            return response()->json([
                'success' => true,
                'message' => 'Notification envoyée à tous les clients'
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’envoi',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }


    public function envoyer_notification_tous_marchands(Request $request){
        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'body' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $marchands = Marchand::whereNotNull('device_token')->get();

            if ($marchands->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun marchand avec device_token'
                ], 404);
            }
            $notifications = [];
            foreach ($marchands as $marchand) {
                $notifications[] = Notification::create([
                    'type' => 'Promotion',
                    'title' => $request->title,
                    'body' => $request->body,
                    'role' => 'marchand',
                    'id_user' => $marchand->id,
                ]);
            }
            app(PushNotifController::class)->sendPushBatch($notifications);


            return response()->json([
                'success' => true,
                'message' => 'Notification envoyée à tous les marchands'
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’envoi',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }

    public function envoyer_notification_tout_le_monde(Request $request){
        $validator = Validator::make($request->all(), [
            'title' => 'required',
            'body' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $clients = User::whereNotNull('device_token')->get();
            $marchands = Marchand::whereNotNull('device_token')->get();

            if ($clients->isEmpty() && $marchands->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun utilisateur avec device_token'
                ], 404);
            }
            $notif_clients = [];
            foreach ($clients as $client) {
                $notif_clients[] = Notification::create([
                    'type' => 'Promotion',
                    'title' => $request->title,
                    'body' => $request->body,
                    'role' => 'client',
                    'id_user' => $client->id,
                ]);
            }
            app(PushNotifController::class)->sendPushBatch($notif_clients);

            $notif_marchands = [];
            foreach ($marchands as $marchand) {
                $notif_marchands[] = Notification::create([
                    'type' => 'Promotion',
                    'title' => $request->title,
                    'body' => $request->body,
                    'role' => 'marchand',
                    'id_user' => $marchand->id,
                ]);
                app(PushNotifController::class)->sendPushBatch($notif_marchands);
            }

            return response()->json([
                'success' => true,
                'message' => 'Notification envoyée à tous (clients et marchands)'
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’envoi',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }


    public function notif_client(Request $request){
        try {
            $user = $request->user();
            $client = User::find($user->id);

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client non trouvé'
                ], 404);
            }

            $query = Notification::where('id_user', $client->id);

            // 🔥 Filtre is_read via query params
            if ($request->has('is_read')) {
                $query->where('is_read', (bool) $request->is_read);
            }

            $notifications = $query
                ->orderBy('created_at', 'desc')
                ->get();

            if ($notifications->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucune notification trouvée'
                ], 200);
            }

            $data = $notifications->map(fn ($notification) => [
                'id'         => $notification->id,
                'type'       => $notification->type,
                'title'      => $notification->title,
                'message'    => $notification->body,
                'isRead'     => (bool) $notification->is_read,
                'created_at' => $notification->created_at,
            ]);

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Notifications affichées avec succès'
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage des notifications du client',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }


    public function notif_marchand(Request $request){
        try {
            $user = $request->user();
            $marchand = Marchand::find($user->id);

            if (!$marchand) {
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand non trouvé'
                ], 404);
            }

            $query = Notification::where('id_user', $marchand->id);

            // 🔥 Filtre is_read
            if ($request->has('is_read')) {
                $query->where('is_read', (bool) $request->is_read);
            }

            $notifications = $query
                ->orderBy('created_at', 'desc')
                ->get();

            if ($notifications->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucune notification trouvée'
                ], 200);
            }

            $data = $notifications->map(fn ($notification) => [
                'id'         => $notification->id,
                'type'       => $notification->type,
                'title'      => $notification->title,
                'message'    => $notification->body,
                'isRead'     => (bool) $notification->is_read,
                'created_at' => $notification->created_at,
            ]);

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Notifications affichées avec succès'
            ], 200);

        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage des notifications du marchand',
                'erreur' => $e->getMessage()
            ], 500);
        }
    }

    public function notif_marchand_lue(Request $request){
        try{
            $user = $request->user();
            $marchand = Marchand::find($user->id);
            if(!$marchand){
                return response()->json([
                    'success' => false,
                    'message' => 'Marchand introuvable'
                ],404);
            }

            $notifications = Notification::where('id_user', $marchand->id)->where('is_read', '!=', 1)->get();
            $notifications->map(function($notification){
                $notification->is_read = 1;
                $notification->save();
            });
            return response()->json([
                'success' => true,
                'message' => 'Toute les notifications mises à lues avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise des notifications du marchand à lue.',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function notif_client_lue(Request $request){
        try{
            $user = $request->user();
            $client = User::find($user->id);
            if(!$client){
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable'
                ],404);
            }

            $notifications = Notification::where('id_user', $client->id)->where('is_read', '!=', 1)->get();
            $notifications->map(function($notification){
                $notification->is_read = 1;
                $notification->save();
            });
            return response()->json([
                'success' => true,
                'message' => 'Toute les notifications mises à lues avec succès'
            ],200);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise des notifications du client à lue.',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

    public function nombre_notif_non_lue(Request $request){
        try{
            $user = $request->user();
            $marchand = Marchand::find($user->id);
            $client = User::find($user->id);
            if($marchand){
                $notification = Notification::where('id_user', $marchand->id)->where('is_read', 0)->count();
                return response()->json([
                    'success' => true,
                    'data' => $notification,
                    'message' => 'Nombre de notifications non lues du marchand affichées avec succès'
                ],200);
            }
            if($client){
                $notification = Notification::where('id_user', $client->id)->where('is_read', 0)->count();
                return response()->json([
                    'success' => true,
                    'data' => $notification,
                    'message' => 'Nombre de notifications non lues du client affichées avec succès'
                ],200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable'
            ],404);
        }
        catch(QueryException $e){
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l’affichage des notifications non lues',
                'erreur' => $e->getMessage()
            ],500);
        }
    }

}
