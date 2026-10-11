<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja pasar a quien puede vender: administrador, administrativo y vendedor.
 *
 * El cajero queda afuera: su tarea es la puerta, no la caja. Va despues de
 * tickets.auth, asi que el usuario ya esta autenticado y habilitado.
 */
class TicketsPos
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isPosUser()) {
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
