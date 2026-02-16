<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DebugAuth
{
    public function handle(Request $request, Closure $next)
    {
        // Логируем информацию о запросе
        Log::info('🔍 Auth Debug', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'has_bearer' => $request->bearerToken() ? 'YES' : 'NO',
            'bearer_preview' => $request->bearerToken() ? substr($request->bearerToken(), 0, 20) . '...' : null,
            'headers' => [
                'Authorization' => $request->header('Authorization') ? 'Present' : 'Missing',
                'Accept' => $request->header('Accept'),
                'Origin' => $request->header('Origin'),
            ],
        ]);

        // Если есть токен, проверяем его в БД
        if ($token = $request->bearerToken()) {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if ($tokenModel) {
                Log::info('✅ Token found in DB', [
                    'user_id' => $tokenModel->tokenable_id,
                    'name' => $tokenModel->name,
                    'created' => $tokenModel->created_at,
                    'last_used' => $tokenModel->last_used_at,
                ]);
            } else {
                Log::warning('❌ Token NOT found in DB');
            }
        }

        return $next($request);
    }
}
