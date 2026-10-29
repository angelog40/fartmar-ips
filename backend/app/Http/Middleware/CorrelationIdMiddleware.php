<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-ID') ?: (string) Str::uuid();
        $request->attributes->set('correlation_id', $correlationId);

        // Contexto seguro en logs sin incluir PII de pacientes (RN-10)
        Log::withContext([
            'correlation_id' => $correlationId,
            'user_id'        => $request->user()?->id,
            'path'           => $request->path(),
        ]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}