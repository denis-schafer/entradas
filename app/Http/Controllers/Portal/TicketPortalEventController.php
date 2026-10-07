<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketPortalEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = DB::table('tickets_events as e')
            ->where('e.status', 'published')
            ->select([
                'e.id', 'e.name', 'e.slug', 'e.description', 'e.starts_at', 'e.ends_at',
                'e.location', 'e.cover_image', 'e.capacity',
            ])
            ->orderByRaw('e.starts_at is null, e.starts_at asc');

        // Separar en el propio SQL evita traer todos los eventos para paginar
        // en memoria cuando hay mucho historial.
        if ($perPage = $request->query('per_page')) {
            return response()->json($events->paginate(min((int) $perPage, 60)));
        }

        return response()->json($events->get());
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $event = DB::table('tickets_events')
            ->where('slug', $slug)
            ->where('status', 'published')
            ->first();

        if (! $event) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        $types = DB::table('tickets_event_ticket_types')
            ->where('event_id', $event->id)
            ->where('enable', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'description', 'image_path', 'price', 'stock', 'sold_count',
                'max_per_order', 'sale_start_at', 'sale_end_at',
                'payment_mode', 'max_installments',
                'wristband_color', 'wristband_label']);

        $now = now();

        foreach ($types as $type) {
            $type->available = ($type->stock === null || $type->sold_count < $type->stock)
                && (! $type->sale_start_at || $type->sale_start_at <= $now)
                && (! $type->sale_end_at || $type->sale_end_at >= $now);
            $type->remaining = $type->stock === null ? null : max(0, $type->stock - $type->sold_count);
        }

        $event->ticket_types = $types;

        $event->ads = DB::table('tickets_ads')
            ->where('enable', true)
            ->where(fn ($q) => $q->where('event_id', $event->id)->orWhereNull('event_id'))
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'image_path', 'target_url', 'position']);

        return response()->json($event);
    }

    /**
     * Publicidad global del portal (home).
     *
     * Solo entra la que NO tiene evento asignado. La publicidad de un evento
     * puntual viaja dentro del detalle de ese evento, asi un banner creado
     * para un festival no termina arriba del home de todos.
     */
    public function ads(Request $request): JsonResponse
    {
        $now = now();

        $query = DB::table('tickets_ads')
            ->where('enable', true)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now));

        // Cada zona de la pagina pide su posicion. Sin esto el backend devolvia
        // todos los anuncios y el mismo bloque aparecia repetido en cada zona.
        if ($position = $request->query('position')) {
            $query->where('position', $position);
        }

        // Los anuncios de un evento se suman a los globales: un patrocinio
        // puntual aparece en la pagina de ese evento sin sacar la publicidad
        // comun de la casa.
        if ($eventId = $request->query('event_id')) {
            $query->where(fn ($q) => $q->where('event_id', (int) $eventId)->orWhereNull('event_id'));
        } else {
            $query->whereNull('event_id');
        }

        return response()->json(
            $query
                ->orderBy('position')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'image_path', 'target_url', 'position', 'event_id'])
        );
    }

    /**
     * Configuracion publica del portal: identidad y textos. Sin secretos,
     * la API de configuracion del panel es la que los maneja.
     */
    public function config(): JsonResponse
    {
        $configs = DB::table('tickets_configs')
            ->whereIn('name', [
                'business_name', 'portal_logo', 'portal_primary_color', 'portal_secondary_color',
                'welcome_message', 'success_message', 'rejection_message',
                'terms_url', 'privacy_url',
            ])
            ->pluck('value', 'name');

        return response()->json([
            'business_name' => $configs['business_name'] ?? '',
            'portal_logo' => $configs['portal_logo'] ?? '',
            'portal_primary_color' => $configs['portal_primary_color'] ?? '#7C5CFF',
            'portal_secondary_color' => $configs['portal_secondary_color'] ?? '#FF3D71',
            'welcome_message' => $configs['welcome_message'] ?? '',
            'success_message' => $configs['success_message'] ?? '',
            'rejection_message' => $configs['rejection_message'] ?? '',
            'terms_url' => $configs['terms_url'] ?? '',
            'privacy_url' => $configs['privacy_url'] ?? '',
        ]);
    }
}