<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckMarchandActive
{
    public function handle(Request $request, Closure $next)
    {
        $marchand = $request->user();

        if ($marchand && !$marchand->is_active) {
            $marchand->device_token = null;
            $marchand->save();
            return response()->json([
                'success' => false,
                'message' => 'Votre compte est désactivé'
            ], 403);
        }

        return $next($request);
    }
}

