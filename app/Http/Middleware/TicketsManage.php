<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja pasar al administrador y al administrativo.
 *
 * Separa las secciones que el administrativo comparte con el admin (las
 * estadisticas de sus eventos y la gestion de vendedores) de las que son
 * exclusivas del admin. Va despues de tickets.auth, asi que el usuario ya esta
 * autenticado, habilitado y es del panel.
 */
class TicketsManage
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->canManageUsers()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Tu usuario no tiene acceso a esta seccion',
                ], 403);
            }

            return redirect()->away('/')->with('tickets_denied', 'Sin permisos para esta seccion');
        }

        return $next($request);
    }
}
