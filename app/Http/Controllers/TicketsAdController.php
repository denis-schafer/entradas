<?php

namespace App\Http\Controllers;

use App\Support\DateInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketsAdController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('tickets_ads')->orderByDesc('id');

        if ($eventId = $request->query('event_id')) {
            $query->where('event_id', (int) $eventId);
        }

        if ($position = $request->query('position')) {
            $query->where('position', $position);
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Anuncios vigentes para el portal. Sin sesion: es contenido publico del
     * sitio, y el frontend lo pide para pintar las zonas de publicidad.
     */
    public function active(Request $request): JsonResponse
    {
        $now = now();

        $query = DB::table('tickets_ads')
            ->where('enable', true)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now))
            ->orderBy('position')
            ->orderBy('sort_order')
            ->orderByDesc('id');

        if ($eventId = $request->query('event_id')) {
            $query->where(fn ($q) => $q->where('event_id', (int) $eventId)->orWhereNull('event_id'));
        }

        return response()->json($query->get([
            'id', 'name', 'image_path', 'target_url', 'position', 'event_id',
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => 'nullable|integer|exists:tickets_events,id',
            'name' => 'required|string|max:150',
            'image_path' => 'required|string|max:255',
            'target_url' => 'nullable|string|max:500',
            'position' => 'required|in:top,banner,sidebar,modal',
            'sort_order' => 'nullable|integer',
            'enable' => 'boolean',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
        ]);

        $id = DB::table('tickets_ads')->insertGetId([
            ...DateInput::normalize($validated, ['start_at', 'end_at']),
            'sort_order' => $validated['sort_order'] ?? 0,
            'enable' => $validated['enable'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'message' => 'Publicidad creada'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! DB::table('tickets_ads')->where('id', $id)->exists()) {
            return response()->json(['message' => 'Publicidad no encontrada'], 404);
        }

        $validated = $request->validate([
            'event_id' => 'nullable|integer|exists:tickets_events,id',
            'name' => 'sometimes|string|max:150',
            'image_path' => 'sometimes|string|max:255',
            'target_url' => 'nullable|string|max:500',
            'position' => 'sometimes|in:top,banner,sidebar,modal',
            'sort_order' => 'nullable|integer',
            'enable' => 'boolean',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
        ]);

        DB::table('tickets_ads')->where('id', $id)->update([
            ...DateInput::normalize($validated, ['start_at', 'end_at']),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Publicidad actualizada']);
    }

    public function destroy(int $id): JsonResponse
    {
        DB::table('tickets_ads')->where('id', $id)->delete();

        return response()->json(['message' => 'Publicidad eliminada']);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:5120']);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
            return response()->json(['message' => 'Formato no soportado'], 422);
        }

        $filename = 'ad_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
        $dir = public_path('uploads/tickets/ads');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, $filename);

        return response()->json(['url' => '/uploads/tickets/ads/'.$filename]);
    }
}