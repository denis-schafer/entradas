<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Payments\EventPaymentMethods;
use App\Services\Realtime;
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
        $actor = $request->user();

        $query = User::query()
            ->where('is_admin', true)
            ->select(['id', 'name', 'email', 'dni', 'phone', 'role', 'enable', 'must_change_password', 'last_login_at', 'created_at']);

        /*
        | El administrativo no ve a los admins ni a otros administrativos: solo
        | a los vendedores que comparten alguno de sus eventos. Es la misma
        | lista que puede gestionar, para que el listado y los permisos no se
        | contradigan.
        */
        if ($actor && $actor->isAdministrativo()) {
            $sharedIds = DB::table('tickets_event_user')
                ->whereIn('event_id', $actor->assignedEventIds() ?: [0])
                ->pluck('user_id');

            $query->where('role', User::ROLE_SELLER)
                ->whereIn('id', $sharedIds);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByDesc('id')->paginate(20);

        /*
        | Carga los eventos asignados a los usuarios de la pagina para que el
        | form de edicion arranque con las selecciones ya puestas y la tabla
        | pueda mostrar de cuantos eventos se trata cada cajero.
        */
        $assigned = $this->eventsByUser($users->pluck('id')->all());
        $paymentMethods = $this->paymentMethodsByUser($users->pluck('id')->all());

        foreach ($users->items() as $user) {
            $user->events = $assigned[$user->id] ?? [];
            $user->payment_method_ids = $paymentMethods[$user->id] ?? [];
        }

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:8',
            'dni' => 'nullable|string|max:30',
            'phone' => 'nullable|string|max:30',
            /*
            | admin entra al panel entero; cajero solo escanea y entrega
            | pulseras; vendedor solo vende en efectivo; administrativo ve las
            | estadisticas de sus eventos, da de alta vendedores y vende. Sin
            | esto, "crear usuario del panel" seria sinonimo de administrador.
            */
            'role' => ['nullable', Rule::in([
                User::ROLE_ADMIN,
                User::ROLE_CASHIER,
                User::ROLE_ADMINISTRATIVO,
                User::ROLE_SELLER,
            ])],
            'enable' => 'boolean',
            /*
            | Eventos a los que este operador (cajero, vendedor o administrativo)
            | va a poder entrar. El administrador ignora la asignacion y ve
            | todos los eventos igual. Sin asignaciones, no ve ni opera ninguno.
            */
            'event_ids' => 'sometimes|array',
            'event_ids.*' => 'integer|exists:tickets_events,id',
            /*
            | Medios de pago que este operador puede cobrar desde el POS (ademas
            | del efectivo, que se cobra siempre). Lista vacia = solo efectivo.
            | Solo aplica a los roles que venden (vendedor y administrativo).
            */
            'payment_method_ids' => 'sometimes|array',
            'payment_method_ids.*' => 'integer|exists:tickets_payment_methods,id',
        ]);

        $role = $validated['role'] ?? User::ROLE_ADMIN;
        $eventIds = $validated['event_ids'] ?? [];
        $paymentMethodIds = $validated['payment_method_ids'] ?? [];

        /*
        | Un administrativo solo puede dar de alta vendedores, y solo en sus
        | propios eventos. Se ignora cualquier otro rol que mande el cliente.
        */
        if ($actor && $actor->isAdministrativo()) {
            $role = User::ROLE_SELLER;
            $eventIds = $this->restrictEventIds($actor, $eventIds);
        }

        $dni = isset($validated['dni']) ? preg_replace('/\D+/', '', $validated['dni']) : null;
        $dni = ($dni === '' ? null : $dni);

        $id = DB::table('users')->insertGetId([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'dni' => $dni,
            'password' => Hash::make($validated['password']),
            'is_admin' => true,
            'role' => $role,
            'enable' => $validated['enable'] ?? true,
            'must_change_password' => $validated['enable'] ?? true,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
        | Las asignaciones de evento solo aplican a los roles que operan acotados
        | (cajero, vendedor, administrativo). Al admin se le deja la tabla
        | limpia para no arrastrar filas viejas.
        */
        $scopedEventIds = $this->appliesEventScope($role) ? $eventIds : [];
        $this->syncEvents($id, $scopedEventIds);

        /*
        | Los medios de pago asignados no pueden salirse de lo que el evento
        | habilita: la lista se recorta a la union de medios disponibles en los
        | eventos del operador.
        */
        if ($this->appliesPaymentScope($role)) {
            $this->syncPaymentMethods($id, $this->restrictPaymentMethodIds($paymentMethodIds, $scopedEventIds));
        }

        return response()->json(['id' => $id, 'message' => 'Usuario creado'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $user = User::find($id);

        if (! $user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        // Un administrativo solo puede tocar vendedores de sus eventos.
        $this->assertManageable($actor, $user);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:150',
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($id)],
            'dni' => 'nullable|string|max:30',
            'phone' => 'nullable|string|max:30',
            'role' => ['sometimes', Rule::in([
                User::ROLE_ADMIN,
                User::ROLE_CASHIER,
                User::ROLE_ADMINISTRATIVO,
                User::ROLE_SELLER,
            ])],
            'enable' => 'boolean',
            /*
            | Cambio de contrasena opcional desde el admin: si llega vacio se
            | ignora. Si llega lleno se exige la confirmacion (regla de
            | Laravel "confirmed") y se marca must_change_password como aviso
            | de que la contrasena que tiene puesta es temporal.
            */
            'password' => 'sometimes|string|min:8|confirmed',
            'event_ids' => 'sometimes|array',
            'event_ids.*' => 'integer|exists:tickets_events,id',
            'payment_method_ids' => 'sometimes|array',
            'payment_method_ids.*' => 'integer|exists:tickets_payment_methods,id',
        ]);

        /*
        | Un administrativo no puede cambiarle el rol a un vendedor (ni
        | promoverlo), y solo lo mueve dentro de sus propios eventos.
        */
        if ($actor && $actor->isAdministrativo()) {
            $validated['role'] = User::ROLE_SELLER;

            if (array_key_exists('event_ids', $validated)) {
                $validated['event_ids'] = $this->restrictEventIds($actor, $validated['event_ids']);
            }
        }

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
        | Al desactivar a un operador hay que sacarlo al instante: si no,
        | seguiria operando con la sesion abierta hasta que expire. Se borran
        | sus sesiones y el remember_token (si no, la cookie "recordarme" lo
        | volveria a loguear) y se le avisa por websocket para que cierre la
        | pestana. El login ya rechaza a los deshabilitados, asi que despues
        | tampoco puede volver a entrar.
        */
        if ($willBeDisabled) {
            DB::table('sessions')->where('user_id', $id)->delete();
            $user->forceFill(['remember_token' => null])->save();

            Realtime::userStatus($id, false);
        }

        /*
        | Si el admin/operador ingreso una contrasena nueva en el form de
        | edicion, se hashea y se la marca como temporal en el listado.
        */
        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
            $user->must_change_password = true;
            $user->save();
        }

        if (array_key_exists('event_ids', $validated)) {
            $this->syncEvents($id, $validated['event_ids']);
        }

        /*
        | Los medios de pago asignados se recortan a lo disponible en los
        | eventos efectivos del operador (los que acaba de tener tras un
        | cambio de eventos). Al cambiar de rol a uno que no vende, se limpian.
        */
        if (array_key_exists('payment_method_ids', $validated)) {
            $role = $user->roleName();

            if ($this->appliesPaymentScope($role)) {
                $events = DB::table('tickets_event_user')
                    ->where('user_id', $id)
                    ->pluck('event_id')
                    ->all();

                $this->syncPaymentMethods($id, $this->restrictPaymentMethodIds($validated['payment_method_ids'], $events));
            } else {
                $this->syncPaymentMethods($id, []);
            }
        }

        return response()->json(['message' => 'Usuario actualizado']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        $this->assertManageable($request->user(), $user);

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
        $user = User::find($id);

        if (! $user) {
            return response()->json(['message' => 'Usuario no encontrado'], 404);
        }

        $this->assertManageable($request->user(), $user);

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
     * Reemplaza los eventos asignados a un usuario del panel. Si la lista viene
     * vacia queda sin ninguno, que para un cajero significa no ver ni escanear
     * nada hasta que se le asigne alguno.
     */
    private function syncEvents(int $userId, array $eventIds): void
    {
        DB::table('tickets_event_user')->where('user_id', $userId)->delete();

        $now = now();

        foreach (array_unique(array_map('intval', $eventIds)) as $eventId) {
            DB::table('tickets_event_user')->insert([
                'user_id' => $userId,
                'event_id' => $eventId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reemplaza los medios de pago que un operador puede cobrar desde el POS.
     * El efectivo no se guarda: se cobra siempre.
     */
    private function syncPaymentMethods(int $userId, array $methodIds): void
    {
        DB::table('tickets_user_payment_method')->where('user_id', $userId)->delete();

        $now = now();

        foreach (array_unique(array_map('intval', $methodIds)) as $methodId) {
            DB::table('tickets_user_payment_method')->insert([
                'user_id' => $userId,
                'payment_method_id' => $methodId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Deja, de los medios pedidos, solo los que estan disponibles en el
     * conjunto de eventos dado (lo que el evento habilita y tiene credenciales).
     */
    private function restrictPaymentMethodIds(array $methodIds, array $eventIds): array
    {
        $available = EventPaymentMethods::idsForEvents($eventIds);

        return array_values(array_intersect(array_map('intval', $methodIds), $available));
    }

    /**
     * Eventos asignados, agrupados por usuario: [user_id => [{id, name}]].
     * Una sola query extra por pagina de listado.
     */
    private function eventsByUser(array $userIds): array
    {
        if (! $userIds) {
            return [];
        }

        $map = [];

        $rows = DB::table('tickets_event_user as eu')
            ->join('tickets_events as e', 'e.id', '=', 'eu.event_id')
            ->whereIn('eu.user_id', $userIds)
            ->orderBy('e.name')
            ->select(['eu.user_id', 'e.id', 'e.name'])
            ->get();

        foreach ($rows as $row) {
            $map[$row->user_id][] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $map;
    }

    /**
     * Medios de pago asignados, agrupados por usuario: [user_id => [ids]].
     * Una sola query extra por pagina de listado.
     */
    private function paymentMethodsByUser(array $userIds): array
    {
        if (! $userIds) {
            return [];
        }

        $map = [];

        $rows = DB::table('tickets_user_payment_method')
            ->whereIn('user_id', $userIds)
            ->orderBy('payment_method_id')
            ->get(['user_id', 'payment_method_id']);

        foreach ($rows as $row) {
            $map[$row->user_id][] = (int) $row->payment_method_id;
        }

        return $map;
    }

    /**
     * Solo los roles que venden desde el POS llevan medios de pago asignados.
     */
    private function appliesPaymentScope(string $role): bool
    {
        return in_array($role, [
            User::ROLE_SELLER,
            User::ROLE_ADMINISTRATIVO,
        ], true);
    }

    /**
     * Evita dejar el panel sin ningun administrador de verdad.
     *
     * Cuenta solo los role=admin (o NULL, que es como quedaron los operadores
     * previos al rol): un cajero, vendedor o administrativo activo no sirve de
     * respaldo, porque ninguno puede reconfigurar MercadoPago ni dar de alta a
     * otro administrador. Si no, desactivar al ultimo administrador mientras
     * hay un vendedor en turno dejaria la cuenta sin salida para siempre.
     */
    private function isLastActiveAdmin(int $id): bool
    {
        $user = User::find($id);

        if (! $user?->is_admin) {
            return false;
        }

        $activeAdmins = User::where('is_admin', true)
            ->where('enable', true)
            ->where(fn ($q) => $q->where('role', User::ROLE_ADMIN)->orWhereNull('role'))
            ->count();

        // Si el usuario que se toca no es administrador, no es el ultimo admin.
        if (! $user->isStaffAdmin()) {
            // Solo se bloquea si ademas es el ultimo usuario activo del panel,
            // para no dejar la instalacion sin ningun acceso.
            return User::where('is_admin', true)->where('enable', true)->count() <= 1;
        }

        return $activeAdmins <= 1;
    }

    /**
     * Que un administrativo solo pueda tocar a los vendedores de sus eventos.
     * El administrador no tiene limites: assertManageable no hace nada.
     */
    private function assertManageable(?User $actor, User $target): void
    {
        if (! $actor || ! $actor->isAdministrativo()) {
            return;
        }

        if ($target->roleName() !== User::ROLE_SELLER) {
            abort(403, 'Solo podes gestionar vendedores.');
        }

        $shared = array_intersect($actor->assignedEventIds(), $target->assignedEventIds());

        if (! $shared) {
            abort(403, 'Ese vendedor no pertenece a tus eventos.');
        }
    }

    /**
     * Deja, de una lista de eventos, solo los que el administrativo tiene
     * asignados. Evita que asigne vendedores a eventos que no administra.
     */
    private function restrictEventIds(User $actor, array $eventIds): array
    {
        $assigned = $actor->assignedEventIds();

        return array_values(array_intersect(array_map('intval', $eventIds), $assigned));
    }

    /**
     * Solo los roles que operan acotados a eventos guardan asignaciones. El
     * administrador ve todo y no necesita filas en el pivote.
     */
    private function appliesEventScope(string $role): bool
    {
        return in_array($role, [
            User::ROLE_CASHIER,
            User::ROLE_SELLER,
            User::ROLE_ADMINISTRATIVO,
        ], true);
    }
}