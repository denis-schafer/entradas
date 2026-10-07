<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege el panel de administracion.
 *
 * Solo deja pasar a usuarios que ademas son admin: un comprador autenticado
 * tiene sesion valida, pero no debe ver ni una sola pantalla del panel.
 */
class TicketsAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->deny($request, 'No autenticado');
        }

        if (! $user->enable) {
            return $this->deny($request, 'El usuario esta deshabilitado', 403);
        }

        if (! $user->is_admin) {
            return $this->deny($request, 'Sin permisos para el panel', 403);
        }

        return $next($request);
    }

    private function deny(Request $request, string $message, int $status = 401): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return redirect()->away('/')->with('tickets_denied', $message);
    }
}