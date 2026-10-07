<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege el portal de compra.
 *
 * Publica el usuario autenticado en el atributo tickets_portal_user, que es
 * lo que leen los controladores del portal. Se expone como atributo y no como
 * $request->user() para no tener dos caminos de acceso al mismo dato.
 *
 * Un admin autenticado tambien puede comprar entradas: no se le bloquea el
 * portal, porque el flujo de compra no revela nada que el panel no vea.
 */
class TicketsPortalAuth
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

        $request->attributes->set('tickets_portal_user', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'dni' => $user->dni,
            'phone' => $user->phone,
            'is_admin' => (bool) $user->is_admin,
        ]);

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