<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TicketPortalAuthController extends Controller
{
    /**
     * El portal usa el mismo guard de sesion que el panel.
     *
     * Antes el token del portal era base64(id:db:time) y el backend confiaba
     * en el: cualquiera podia forjar uno con un id valido y entrar como otro
     * comprador. Con un solo login en la raiz y una sola tabla users, la
     * sesion firmada por Laravel resuelve el problema y ademas permite que
     * /broadcasting/auth funcione igual para los dos lados.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:150',
            'password' => 'required|string|min:8|confirmed',
            'dni' => ['required', 'string', 'max:30', $this->dniRule()],
            'phone' => 'nullable|string|max:30',
            'consent' => 'required|accepted',
        ]);

        // El DNI se guarda siempre sin puntos ni guiones: el login busca por
        // DNI, y si se guardara con formato "29.923.360" la cuenta quedaria
        // imposible de encontrar desde "29923360".
        $dni = $this->normalizeDni($validated['dni']);

        $exists = DB::table('users')
            ->where(function ($q) use ($validated, $dni) {
                $q->where('email', $validated['email'])->orWhere('dni', $dni);
            })
            ->first();

        if ($exists) {
            $which = $exists->email === $validated['email'] ? 'ese email' : 'ese DNI';

            return response()->json(['message' => "Ya existe una cuenta con {$which}"], 422);
        }

        $id = DB::table('users')->insertGetId([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'dni' => $dni,
            'phone' => $validated['phone'] ?? null,
            'is_admin' => false,
            'enable' => true,
            'must_change_password' => false,
            'email_verified_at' => now(),
            'consent_accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::find($id);

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'user' => $this->payload($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dni' => 'required|string|max:30',
            'password' => 'required|string',
        ]);

        $dni = $this->normalizeDni($validated['dni']);

        $user = User::where('dni', $dni)->where('enable', true)->first();

        // Mismo mensaje para "no existe" y "contrasena incorrecta": no se
        // confirma que DNI esta registrado.
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'dni' => 'DNI o contrasena incorrectos',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['user' => $this->payload($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['authenticated' => false, 'user' => null]);
        }

        return response()->json([
            'authenticated' => true,
            'user' => $this->payload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesion cerrada']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:150',
            'phone' => 'nullable|string|max:30',
        ]);

        $user->fill($validated)->save();

        return response()->json(['message' => 'Datos actualizados', 'user' => $this->payload($user->refresh())]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'La contrasena actual no coincide'], 422);
        }

        $user->password = $validated['password'];
        $user->save();

        return response()->json(['message' => 'Contrasena actualizada']);
    }

    /**
     * DNI normalizado: solo digitos, entre 7 y 10.
     */
    public static function normalizeDni(string $dni): string
    {
        return (string) preg_replace('/\D/', '', $dni);
    }

    private function dniRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $digits = self::normalizeDni((string) $value);

            if (strlen($digits) < 7 || strlen($digits) > 10) {
                $fail('El DNI debe tener entre 7 y 10 digitos.');
            }
        };
    }

    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'dni' => $user->dni,
            'phone' => $user->phone,
            'is_admin' => (bool) $user->is_admin,
            'role' => $user->isCashier() ? User::ROLE_CASHIER : User::ROLE_ADMIN,
            'routes' => $user->allowedPanelRoutes(),
            // La raiz del SPA pregunta la sesion por aca, no por el endpoint del
            // panel, asi que el flag de "cambiar la contrasena" tiene que viajar
            // tambien en esta respuesta para que un F5 no lo saque del bloqueo.
            'must_change_password' => (bool) $user->must_change_password,
        ];
    }
}