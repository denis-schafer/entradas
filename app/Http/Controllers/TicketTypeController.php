<?php

namespace App\Http\Controllers;

use App\Services\Realtime;
use App\Support\DateInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketTypeController extends Controller
{
    public function index(Request $request, int $eventId): JsonResponse
    {
        if (! DB::table('tickets_events')->where('id', $eventId)->exists()) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        return response()->json(
            DB::table('tickets_event_ticket_types')
                ->where('event_id', $eventId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    public function store(Request $request, int $eventId): JsonResponse
    {
        if (! DB::table('tickets_events')->where('id', $eventId)->exists()) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        $validated = $this->validated($request, true);

        $id = DB::table('tickets_event_ticket_types')->insertGetId([
            ...DateInput::normalize($validated, ['sale_start_at', 'sale_end_at']),
            'event_id' => $eventId,
            'sold_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Realtime::ticketStock(
            $eventId,
            $id,
            $validated['stock'] ?? null,
            0,
            $validated['enable'] ?? true,
        );

        return response()->json(['id' => $id, 'message' => 'Tipo de entrada creado'], 201);
    }

    public function update(Request $request, int $eventId, int $id): JsonResponse
    {
        $type = DB::table('tickets_event_ticket_types')
            ->where('event_id', $eventId)
            ->where('id', $id)
            ->first();

        if (! $type) {
            return response()->json(['message' => 'Tipo no encontrado'], 404);
        }

        $validated = $this->validated($request, false);
        $validated = DateInput::normalize($validated, ['sale_start_at', 'sale_end_at']);
        $validated['updated_at'] = now();

        DB::table('tickets_event_ticket_types')->where('id', $id)->update($validated);

        // Solo se avisa si algo que el portal ve cambio: stock o disponibilidad.
        $stockChanged = array_key_exists('stock', $validated) && $validated['stock'] != $type->stock;
        $enableChanged = array_key_exists('enable', $validated) && (bool) $validated['enable'] !== (bool) $type->enable;

        if ($stockChanged || $enableChanged) {
            $fresh = DB::table('tickets_event_ticket_types')->where('id', $id)->first();
            Realtime::ticketStock(
                $eventId,
                $id,
                $fresh->stock === null ? null : (int) $fresh->stock,
                (int) $fresh->sold_count,
                (bool) $fresh->enable,
            );
        }

        return response()->json(['message' => 'Tipo de entrada actualizado']);
    }

    public function destroy(int $eventId, int $id): JsonResponse
    {
        $type = DB::table('tickets_event_ticket_types')
            ->where('event_id', $eventId)
            ->where('id', $id)
            ->first();

        if (! $type) {
            return response()->json(['message' => 'Tipo no encontrado'], 404);
        }

        if ($type->sold_count > 0) {
            return response()->json([
                'message' => 'No se puede eliminar un tipo con entradas vendidas',
            ], 422);
        }

        DB::table('tickets_event_ticket_types')->where('id', $id)->delete();

        return response()->json(['message' => 'Tipo eliminado']);
    }

    /**
     * Ruta declarada antes que /events/{eventId}/ticket-types para que
     * "upload" no se lea como un id de evento.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:5120']);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            return response()->json(['message' => 'Formato no soportado'], 422);
        }

        $filename = 'type_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
        $dir = public_path('uploads/tickets/types');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, $filename);

        return response()->json(['url' => '/uploads/tickets/types/'.$filename]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $nullable = $creating ? 'nullable' : 'nullable';

        $validated = $request->validate([
            'name' => $required.'|string|max:150',
            'description' => $nullable.'|string',
            'image_path' => $nullable.'|string|max:255',
            'price' => $required.'|numeric|min:0',
            'stock' => $nullable.'|integer|min:1',
            'max_per_order' => $nullable.'|integer|min:1|max:100',
            'sale_start_at' => $nullable.'|date',
            'sale_end_at' => $nullable.'|date|after_or_equal:sale_start_at',
            'payment_mode' => $required.'|in:single,installments,both',
            'max_installments' => $required.'|integer|min:1|max:24',
            'wristband_color' => $nullable.'|string|max:30',
            'wristband_label' => $nullable.'|string|max:50',
            'enable' => 'boolean',
            'sort_order' => $nullable.'|integer',
        ]);

        if ($creating) {
            $validated['enable'] = $validated['enable'] ?? true;
            $validated['sort_order'] = $validated['sort_order'] ?? 0;
        }

        return $validated;
    }
}