<?php

namespace App\Http\Controllers;

use App\Services\Realtime;
use App\Support\DateInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TicketsEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('tickets_events')->orderByDesc('id');

        /*
        | Un cajero solo ve los eventos que le estan asignados: con la lista
        | vacia no ve ninguno y no puede escanear hasta que se le asigne
        | alguno. El administrador pasa por todos los eventos igual.
        */
        if ($request->user()?->isCashier()) {
            $query->whereIn('tickets_events.id', $request->user()->assignedEventIds());
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('all')) {
            return response()->json($query->get());
        }

        return response()->json($query->paginate(20));
    }

    public function show(int $id): JsonResponse
    {
        $event = DB::table('tickets_events')->where('id', $id)->first();

        if (! $event) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        $event->ticket_types = DB::table('tickets_event_ticket_types')
            ->where('event_id', $id)
            ->orderBy('sort_order')
            ->get();

        /*
        | El access_token de MP es por evento: si ya esta cargado, lo
        | enmascaramos como '__set__' en la respuesta para no exponer el
        | secreto. El frontend usa el flag "mp_connected" para pintar el badge
        | y deja vacio el input hasta que el operador lo cambie.
        */
        if (! empty($event->mp_access_token)) {
            $event->mp_access_token = '__set__';
            $event->mp_connected = true;
        } else {
            $event->mp_access_token = '';
            $event->mp_connected = false;
        }

        return response()->json($event);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'location' => 'nullable|string|max:255',
            'capacity' => 'nullable|integer|min:1',
            'cover_image' => 'nullable|string|max:255',
            'status' => 'required|in:draft,published,closed,cancelled',
            /*
            | Access token de MercadoPago del organizador. OAuth lo guarda aca
            | (viene del code que devuelve MP). Tambien se puede pegar a mano.
            | Los pagos de las entradas de este evento caen en la cuenta MP
            | que autorizo este token.
            */
            'mp_access_token' => 'nullable|string|max:255',
        ]);

        // El navegador manda ISO con Z (UTC) y la columna es datetime pelada:
        // hay que convertir a la zona de la app antes de insertar.
        $validated = DateInput::normalize($validated, ['starts_at', 'ends_at']);

        $id = DB::table('tickets_events')->insertGetId([
            ...$validated,
            'slug' => $this->uniqueSlug($validated['name']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Realtime::eventStatus($id, $validated['status'], $validated['cover_image'] ?? null);

        return response()->json([
            'id' => $id,
            'slug' => DB::table('tickets_events')->where('id', $id)->value('slug'),
            'message' => 'Evento creado',
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $event = DB::table('tickets_events')->where('id', $id)->first();

        if (! $event) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'location' => 'nullable|string|max:255',
            'capacity' => 'nullable|integer|min:1',
            'cover_image' => 'nullable|string|max:255',
            'status' => 'sometimes|in:draft,published,closed,cancelled',
            'mp_access_token' => 'sometimes|nullable|string|max:255',
        ]);

        if (isset($validated['name']) && $validated['name'] !== $event->name) {
            $validated['slug'] = $this->uniqueSlug($validated['name'], $id);
        }

        /*
        | El frontend enmascara el token con '__set__' cuando ya esta cargado
        | (asi no expone el secreto en la respuesta). Tambien puede llegar
        | null o vacio cuando el operador no toco el campo: eso significa
        | "no quiero cambiar el token guardado", nunca "borrar lo que tengo".
        |
        | El unico caso en el que SI se borra es cuando el operador tilda
        | "Desconectar MercadoPago" en el form: ahi llega el sentinel
        | '__disconnect__' y se setea null a proposito.
        |
        | Antes solo se protegia el caso '__set__'. El resto caia en el update()
        | y pisaba el token a null, borrando la conexion con MP en cada
        | guardado del evento (incluso sin modificar el campo).
        */
        $mpToken = $validated['mp_access_token'] ?? null;

        if ($mpToken === '__disconnect__') {
            $validated['mp_access_token'] = null;
        } elseif ($mpToken === '__set__' || $mpToken === null || $mpToken === '') {
            unset($validated['mp_access_token']);
        }

        $validated = DateInput::normalize($validated, ['starts_at', 'ends_at']);

        $validated['updated_at'] = now();
        DB::table('tickets_events')->where('id', $id)->update($validated);

        // El estado y la portada son lo que el portal ve. Si cambiaron, se avisa.
        if (isset($validated['status']) && $validated['status'] !== $event->status) {
            Realtime::eventStatus($id, $validated['status'], $validated['cover_image'] ?? $event->cover_image);
        }

        return response()->json(['message' => 'Evento actualizado']);
    }

    /**
     * Elimina un evento SOLO si no tiene historia: nada de ordenes (pagadas o
     * no), ni entradas, ni escaneos. Si hay algo, el evento se archiva pasandolo
     * a "cancelled": los ON DELETE CASCADE de las migraciones borrarian en
     * cascada ordenes, entradas y escaneos, y con ellos el registro de ventas y
     * los QR ya emitidos.
     */
    public function destroy(int $id): JsonResponse
    {
        $event = DB::table('tickets_events')->where('id', $id)->first();

        if (! $event) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        $blockers = [
            'ordenes' => DB::table('tickets_orders')->where('event_id', $id)->count(),
            'entradas' => DB::table('tickets_tickets')->where('event_id', $id)->count(),
            'escaneos' => DB::table('tickets_scans')->where('event_id', $id)->count(),
        ];

        if (array_sum($blockers) > 0) {
            DB::table('tickets_events')->where('id', $id)->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

            Realtime::eventStatus($id, 'cancelled', $event->cover_image);

            return response()->json([
                'message' => 'El evento tiene historial, no se borra: se cancelo y dejo de estar visible en el portal.',
                'archived' => true,
                'counts' => $blockers,
            ], 422);
        }

        DB::table('tickets_events')->where('id', $id)->delete();

        Realtime::eventStatus($id, 'deleted');

        return response()->json(['message' => 'Evento eliminado', 'archived' => false]);
    }

    /**
     * Sube la portada del evento. La ruta se declara antes que /events/{id}
     * para que "upload" no se interprete como un id.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:5120']);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            return response()->json(['message' => 'Formato no soportado'], 422);
        }

        $filename = 'event_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
        $dir = public_path('uploads/tickets/events');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, $filename);

        return response()->json(['url' => '/uploads/tickets/events/'.$filename]);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'evento';
        $slug = $base;
        $i = 1;

        while (DB::table('tickets_events')
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}