<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketsUserController extends Controller
{
    /**
     * Listado de usuarios del panel (admins). Los compradores se ven desde
     * el listado de ordenes, que es donde aporta informacion.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->where('is_admin', true)
            ->select(['id', 'name', 'email', 'dni', 'phone', 'role', 'enable', 'must_change_password', 'last_login_at', 'created_at']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderByDesc('id')->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:8',
            'dni' => 'nullable|string|max:30',
            'phone' => 'nullable|string|max:30',
            // admin entra al panel entero; cajero solo a escanear y a entregar
            // pulseras. Sin esto, "crear usuario del panel" es sinonimo de
            // administrador y no hay forma de dar de alta al de la puerta.
            'role' => ['nullable', Rule::in([User::ROLE_ADMIN, User::ROLE_CASHIER])],
            'enable' => 'boolean',
        ]);

        $dni = isset($validated['dni']) ? preg_replace('/\D+/', '', $validated['dni']) : null;
        $dni = ($dni === '' ? null : $dni);

        $id = DB::table('users')->insertGetId([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'dni' => $dni,
            'password' => Hash::make($validated['password']),
            'is_admin' => true,
            'role' => $validated['role'] ?? User::ROLE_ADMIN,
            'enable' => $validated['enable'] ?? true,
            'must_change_password' => $validated['enable'] ?? true,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'message' => 'Usuario creado'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:150',
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($id)],
            'dni' => 'nullable|string|max:30',
            'phone' => 'nullable|string|max:30',
            'role' => ['sometimes', Rule::in([User::ROLE_ADMIN, User::ROLE_CASHIER])],
            'enable' => 'boolean',
            /*
            | Cambio de contrasena opcional desde el admin: si llega vacio se
            | ignora. Si llega lleno se exige la confirmacion (regla de
            | Laravel "confirmed") y se marca must_change_password como aviso
            | de que la contrasena que tiene puesta es temporal.
            */
            'password' => 'sometimes|string|min:8|confirmed',
        ]);

        /*
        | El DNI se normaliza sin puntos ni guiones antes de guardar, igual que
        | en el portal, asi el login por DNI matchea siempre.
        */
        if (array_key_exists('dni', $validated)) {
            $dni = preg_replace('/\D+/', '', $validated['dni'] ?? '');
            $validated['dni'] = $dni === '' ? null : $dni;
        }

        $willBeDisabled = array_key_exists('enable', $validated) && ! $validated['enable'];

        // Un admin no se puede desactivar a si mismo: dejaria al panel sin
        // ninguna sesion valida desde la que recuperar el acceso.
        if ($willBeDisabled) {
            if ($request->user()?->id === $id) {
                throw ValidationException::withMessages([
                    'enable' => 'No podes desactivar tu propio usuario',
                ]);
            }

            if ($this->isLastActiveAdmin($id)) {
                throw ValidationException::withMessages([
                    'enable' => 'Es el unico administrador activo',
                ]);
            }
        }

        $user->fill($validated)->save();

        /*
        | Si el admin/operador ingreso una contrasena nueva en el form de
        | edicion, se hashea y se la marca como temporal en el listado.
        */
        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
            $user->must_change_password = true;
            $user->save();
        }

        return response()->json(['message' => 'Usuario actualizado']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        if ($request->user()?->id === $id) {
            return response()->json(['message' => 'No podes eliminar tu propio usuario'], 422);
        }

        if ($this->isLastActiveAdmin($id)) {
            return response()->json(['message' => 'Es el unico administrador activo'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'Usuario eliminado']);
    }

    /**
     * Resetea la contrasena y devuelve la nueva para comunicarsela. El flag
     * must_change_password queda como aviso de que esa contrasena es temporal;
     * ya no obliga a cambiarla al entrar.
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        if (! User::find($id)) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        $newPassword = $request->input('new_password') ?: bin2hex(random_bytes(4));

        DB::table('users')->where('id', $id)->update([
            'password' => Hash::make($newPassword),
            'must_change_password' => true,
            'updated_at' => now(),
        ]);

        return response()->json(['new_password' => $newPassword, 'message' => 'Password reseteado']);
    }

    /**
     * Listado de compradores, para la seccion Compradores del panel. Solo
     * datos que ya estan en sus ordenes, mas la actividad agregada.
     */
    public function buyers(Request $request): JsonResponse
    {
        $query = DB::table('users as u')
            ->leftJoin('tickets_orders as o', 'o.buyer_user_id', '=', 'u.id')
            ->where('u.is_admin', false)
            ->groupBy('u.id', 'u.name', 'u.email', 'u.dni', 'u.phone', 'u.created_at')
            ->select([
                'u.id', 'u.name', 'u.email', 'u.dni', 'u.phone', 'u.created_at',
                DB::raw('COUNT(DISTINCT o.id) as order_count'),
                DB::raw('COALESCE(SUM(CASE WHEN o.status = \'paid\' THEN 1 ELSE 0 END), 0) as paid_count'),
                DB::raw('COALESCE(SUM(CASE WHEN o.status = \'paid\' THEN o.total ELSE 0 END), 0) as total_spent'),
            ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('u.name', 'like', "%{$search}%")
                    ->orWhere('u.email', 'like', "%{$search}%")
                    ->orWhere('u.dni', 'like', "%{$search}%")
                    ->orWhere('u.phone', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderByDesc('total_spent')->paginate(20));
    }

    /**
     * Evita dejar el panel sin ningun administrador de verdad.
     *
     * Cuenta solo los role=admin: un cajero activo no sirve de respaldo, porque
     * no puede reconfigurar MercadoPago ni dar de alta a otro operador. Si no,
     * desactivar al ultimo administrador mientras hay un cajero en turno
     * dejaria la cuenta sin salida para siempre.
     */
    private function isLastActiveAdmin(int $id): bool
    {
        $user = User::find($id);

        if (! $user?->is_admin) {
            return false;
        }

        $activeStaff = User::where('is_admin', true)
            ->where('enable', true)
            ->where(fn ($q) => $q->where('role', '!=', User::ROLE_CASHIER)->orWhereNull('role'))
            ->count();

        // Si el usuario que se toca no es de los que cuentan, no es el ultimo.
        if (! $user->isCashier()) {
            return $activeStaff <= 1;
        }

        return User::where('is_admin', true)->where('enable', true)->count() <= 1;
    }
}