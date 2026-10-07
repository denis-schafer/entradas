<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja pasar unicamente al administrador.
 *
 * Va despues de tickets.auth, asi que el usuario ya esta autenticado, habilitado
 * y con la contrasena al dia. Lo que hace este es la segunda etapa: separar al
 * cajero del resto del panel.
 *
 * El cajero entra al panel porque tickets.auth solo mira is_admin, y tiene que
 * entrar: escanea entradas en la puerta. Pero no puede tocar eventos,
 * estadisticas, ordenes, compradores, publicidad ni configuracion.
 *
 * Que el chequeo viva aca y no en cada controlador es lo que evita que un
 * endpoint nuevo quede abierto por olvido: la ruta declara si es de
 * administracion o de puerta, y el resto se niega solo.
 */
class TicketsStaffOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isStaffAdmin()) {
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