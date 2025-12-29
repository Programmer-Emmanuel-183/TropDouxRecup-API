<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use App\Models\Marchand;
use Illuminate\Support\Facades\Http;

class PushNotifController extends Controller
{
    public function sendPush(Notification $notification): array{
        /**
         * 🔍 Récupération du destinataire selon le rôle
         */
        $recipient = null;

        if ($notification->role === 'client') {
            $recipient = User::find($notification->id_user);
        }

        if ($notification->role === 'marchand') {
            $recipient = Marchand::find($notification->id_user);
        }

        if (!$recipient || empty($recipient->device_token)) {
            return [
                'success' => false,
                'message' => 'Destinataire ou device token introuvable'
            ];
        }

        /**
         * 📦 Payload Expo
         */
        $payload = [
            'to' => $recipient->device_token,
            'sound' => 'default',
            'title' => $notification->title,
            'body' => $notification->body,
        ];

        // data est optionnel
        if (!empty($notification->data)) {
            $payload['data'] = is_array($notification->data)
                ? $notification->data
                : json_decode($notification->data, true);
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip, deflate',
                'Content-Type' => 'application/json',
            ])->post('https://exp.host/--/api/v2/push/send', $payload);

            return [
                'success' => true,
                'expo_response' => $response->json()
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function sendPushBatch(array $notifications): array{
        $messages = [];

        foreach ($notifications as $notification) {

            if ($notification->role === 'client') {
                $recipient = User::find($notification->id_user);
            } else {
                $recipient = Marchand::find($notification->id_user);
            }

            if (!$recipient || empty($recipient->device_token)) {
                continue;
            }

            $message = [
                'to' => $recipient->device_token,
                'sound' => 'default',
                'title' => $notification->title,
                'body' => $notification->body,
            ];

            if (!empty($notification->data)) {
                $message['data'] = is_array($notification->data)
                    ? $notification->data
                    : json_decode($notification->data, true);
            }

            $messages[] = $message;
        }

        if (empty($messages)) {
            return ['success' => false, 'message' => 'Aucun message à envoyer'];
        }

        $responses = [];

        foreach (array_chunk($messages, 100) as $chunk) {
            $responses[] = $this->sendToExpo($chunk);
        }

        return [
            'success' => true,
            'responses' => $responses
        ];
    }

    private function sendToExpo(array $payload, int $attempt = 1): array{
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip, deflate',
                'Content-Type' => 'application/json',
            ])->post('https://exp.host/--/api/v2/push/send', $payload);

            if (!$response->successful() && $attempt === 1) {
                // 🔁 retry une seule fois
                return $this->sendToExpo($payload, 2);
            }

            return $response->json();

        } catch (\Exception $e) {
            if ($attempt === 1) {
                return $this->sendToExpo($payload, 2);
            }

            return [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }
    }


}
