<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TicketsAuthController extends Controller
{
    /**
     * Login unico de la app.
     *
     * Es el que usa la pantalla de acceso: una sola forma, un solo campo, y el
     * backend decide. Si el identificador es el email de un operador entra al
     * panel; si es el DNI de un comprador entra al portal. La SPA no elige: lee
     * is_admin de la respuesta y monta el shell que corresponde.
     *
     * El campo se rotula DNI en la pantalla, pero aca se aceptan las dos cosas.
     * Mostrar "Email o DNI" hacia dudar al comprador sobre cual de los dos tiene
     * que escribir; restricting el backend a DNI dejaria fuera a todo el personal
     * del evento, que entra con el email que le dio la organizacion. Que el
     * backend sea mas amplio que la etiqueta no molesta: nadie que quiera
     * entering con un DNI necesita saber que el email tambien funciona.
     *
     * El mensaje de error es siempre el mismo para "no existe" y para
     * "contrasena incorrecta": no se confirma que emails o DNI estan dados de
     * alta.
     */
    public function loginUnified(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
        ]);

        $identifier = Str::lower(trim($data['identifier']));
        $dni = $this->normalizeDni($identifier);

        $user = User::where('email', $identifier)
            ->when($dni !== '', fn ($q) => $q->orWhere('dni', $dni))
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->fail('Credenciales invalidas.', 401);
        }

        // Un usuario deshabilitado no entra por ninguna puerta. El mensaje si
        // lo dice, porque el que intenta entrar es el propio usuario y no tiene
        // por qué adivinar si se equivocó de clave o lo desactivaron.
        if (! $user->enable) {
            return $this->fail('El usuario esta deshabilitado.', 403);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'user' => $this->userPayload($user),
            'must_change_password' => (bool) $user->must_change_password,
            'is_admin' => (bool) $user->is_admin,
        ]);
    }

    /**
     * Login del panel. Corre con sesion web (guard 'web'), asi que el usuario
     * queda disponible para /broadcasting/auth y para el canal privado
     * tickets.admin.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
        ]);

        $identifier = Str::lower(trim($data['email']));
        $dni = $this->normalizeDni($identifier);

        /*
        | Mismo criterio que loginUnified: si parece email busca por email, y
        | ademas por DNI normalizado. Asi el admin/cajero puede entrar con
        | cualquiera de los dos, como ya hace el portal.
        */
        $user = User::where('email', $identifier)
            ->when($dni !== '', fn ($q) => $q->orWhere('dni', $dni))
            ->first();

        if (! $user) {
            return $this->fail('Credenciales invalidas.', 401);
        }

        if (! Hash::check($data['password'], $user->password)) {
            return $this->fail('Credenciales invalidas.', 401);
        }

        if (! $user->enable) {
            return $this->fail('El usuario esta deshabilitado.', 403);
        }

        if (! $user->is_admin) {
            return $this->fail('Ese usuario no tiene acceso al panel.', 403);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'user' => $this->userPayload($user),
            'must_change_password' => (bool) $user->must_change_password,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesion cerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'authenticated' => $user !== null,
            'user' => $user ? $this->userPayload($user) : null,
        ]);
    }

    /**
     * Renueva la sesion desde el aviso de "por vencer".
     *
     * Cualquier request desliza la cookie de sesion (StartSession la reencola
     * con la ventana completa), asi que con responder 200 alcanza para que el
     * contador del frontend vuelva a empezar. Si no hay usuario, 401: la sesion
     * ya vencio y el frontend tiene que volver al login.
     */
    public function refreshSession(Request $request): JsonResponse
    {
        if (! $request->user()) {
            return $this->fail('Sesion vencida.', 401);
        }

        return response()->json([
            'message' => 'Sesion renovada.',
            'expires_in' => (int) config('session.lifetime') * 60,
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return $this->fail('La contrasena actual no coincide.', 422);
        }

        $user->password = $data['password'];
        $user->must_change_password = false;
        $user->save();

        return response()->json(['message' => 'Contrasena actualizada.']);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'dni' => $user->dni,
            'is_admin' => (bool) $user->is_admin,
            // El rol decide que pantallas monta el panel. El frontend usa esta
            // misma lista que el middleware, asi que el menu nunca ofrece algo
            // que despues el servidor responde con 403.
            'role' => $user->isCashier() ? User::ROLE_CASHIER : User::ROLE_ADMIN,
            'routes' => $user->allowedPanelRoutes(),
            // Solo aviso (la contrasena temporal sigue vigente hasta que el
            // usuario la cambie); ya no bloquea el panel.
            'must_change_password' => (bool) $user->must_change_password,
        ];
    }

    /**
     * Deja solo los digitos, como el resto de la app: el usuario escribe
     * "29.923.360" o "29923360" y tiene que ser el mismo DNI.
     */
    private function normalizeDni(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}