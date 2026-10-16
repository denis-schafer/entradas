<?php

namespace App\Http\Controllers;

use App\Services\Realtime;
use App\Support\DateInput;
use App\Support\PaymentMethodFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sorteos (rifas) de un evento.
 *
 * Un sorteo es un filtro guardado sobre las entradas del evento (medio de
 * pago, rango de numeros, rango de fechas, elegibilidad) y una lista de
 * premios. Sortear es elegir al azar una entrada del pool y guardarla como
 * ganadora del premio. El pool se recalcula en cada sorteo, con los premios ya
 * adjudicados descontados si el sorteo no permite ganadores repetidos.
 *
 * El admin ve todos los eventos; el administrativo solo los asignados, igual
 * que en estadisticas (ver TicketsStatisticsController::scopeEventIds).
 */
class TicketsRaffleController extends Controller
{
    private const PAYMENT_METHOD_VALUES = [
        'efectivo',
        'mercadopago',
        'multipago:qr',
        'multipago:button',
    ];

    public function index(Request $request): JsonResponse
    {
        $allowedIds = $this->scopeEventIds($request, $request->query('event_id') ? (int) $request->query('event_id') : null);

        $query = DB::table('tickets_raffles as r')
            ->join('tickets_events as e', 'e.id', '=', 'r.event_id')
            ->when($allowedIds !== null, fn ($q) => $q->whereIn('r.event_id', $allowedIds))
            ->orderByDesc('r.id')
            ->select([
                'r.id', 'r.event_id', 'r.public_token', 'r.name', 'r.status',
                'r.number_from', 'r.number_to', 'r.date_from', 'r.date_to',
                'r.only_paid', 'r.include_used', 'r.include_cancelled',
                'r.allow_repeat_winners', 'r.created_at',
                'e.name as event_name',
                DB::raw('(SELECT COUNT(*) FROM tickets_raffle_prizes p WHERE p.raffle_id = r.id) as prizes_count'),
                DB::raw('(SELECT COUNT(*) FROM tickets_raffle_prizes p WHERE p.raffle_id = r.id AND p.winner_ticket_id IS NOT NULL) as drawn_count'),
            ]);

        return response()->json(['data' => $query->get()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $raffle = $this->findScoped($request, $id);

        if (! $raffle) {
            return response()->json(['message' => 'Sorteo no encontrado'], 404);
        }

        return response()->json($this->rafflePayload($raffle));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $this->assertEventAllowed($request, (int) $validated['event_id']);

        $raffleId = DB::transaction(function () use ($validated, $request) {
            $id = DB::table('tickets_raffles')->insertGetId([
                ...$this->fields($validated),
                'event_id' => (int) $validated['event_id'],
                'public_token' => (string) Str::uuid(),
                'status' => $validated['status'] ?? 'draft',
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncPrizes($id, $validated['prizes']);

            return $id;
        });

        return response()->json([
            'id' => $raffleId,
            'message' => 'Sorteo creado',
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $raffle = $this->findScoped($request, $id);

        if (! $raffle) {
            return response()->json(['message' => 'Sorteo no encontrado'], 404);
        }

        $validated = $request->validate($this->rules(false));

        if (isset($validated['event_id'])) {
            $this->assertEventAllowed($request, (int) $validated['event_id']);
        }

        DB::transaction(function () use ($raffle, $validated) {
            $fields = $this->fields($validated);

            if (isset($validated['event_id'])) {
                $fields['event_id'] = (int) $validated['event_id'];
            }

            if (isset($validated['status'])) {
                $fields['status'] = $validated['status'];
            }

            $fields['updated_at'] = now();

            DB::table('tickets_raffles')->where('id', $raffle->id)->update($fields);

            if (array_key_exists('prizes', $validated)) {
                $this->syncPrizes($raffle->id, $validated['prizes']);
            }
        });

        return response()->json(['message' => 'Sorteo actualizado']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $raffle = $this->findScoped($request, $id);

        if (! $raffle) {
            return response()->json(['message' => 'Sorteo no encontrado'], 404);
        }

        DB::table('tickets_raffles')->where('id', $raffle->id)->delete();

        return response()->json(['message' => 'Sorteo eliminado']);
    }

    /**
     * Cuantas entradas cumplen hoy el filtro del sorteo. Sirve para avisar
     * antes de sortear ("hay 1.240 entradas en juego") y para el preview.
     */
    public function eligible(Request $request, int $id): JsonResponse
    {
        $raffle = $this->findScoped($request, $id);

        if (! $raffle) {
            return response()->json(['message' => 'Sorteo no encontrado'], 404);
        }

        $count = $this->eligibleQuery($raffle)->count();

        $sample = $this->eligibleQuery($raffle)
            ->orderBy('t.id')
            ->limit(20)
            ->get(['t.id', 't.number', 't.status', 'o.buyer_name', 'o.payment_method', 'o.payment_variant']);

        return response()->json(['count' => $count, 'sample' => $sample]);
    }

    public function draw(Request $request, int $id, int $prizeId): JsonResponse
    {
        $raffle = $this->findScoped($request, $id);

        if (! $raffle) {
            return response()->json(['message' => 'Sorteo no encontrado'], 404);
        }

        $result = DB::transaction(function () use ($raffle, $prizeId, $request) {
            /*
            | Se lockea el sorteo entero durante el sorteo: asi dos clics
            | simultaneos (o dos operadores) no eligen sobre el mismo pool ni
            | adjudican el mismo premio dos veces.
            */
            $locked = DB::table('tickets_raffles')->where('id', $raffle->id)->lockForUpdate()->first();

            $prize = DB::table('tickets_raffle_prizes')
                ->where('id', $prizeId)
                ->where('raffle_id', $locked->id)
                ->first();

            if (! $prize) {
                throw ValidationException::withMessages(['prize' => 'Premio no encontrado']);
            }

            $query = $this->eligibleQuery($locked);
            $count = $query->count();

            if ($count === 0) {
                throw ValidationException::withMessages([
                    'raffle' => 'No hay entradas elegibles para sortear',
                ]);
            }

            $offset = random_int(0, $count - 1);

            $winner = $this->eligibleQuery($locked)
                ->orderBy('t.id')
                ->skip($offset)
                ->limit(1)
                ->first(['t.id', 't.number', 'o.buyer_name as winner_name']);

            DB::table('tickets_raffle_prizes')->where('id', $prize->id)->update([
                'winner_ticket_id' => $winner->id,
                'drawn_at' => now(),
                'drawn_by' => $request->user()->id,
                'updated_at' => now(),
            ]);

            DB::table('tickets_raffle_draws')->insert([
                'raffle_id' => $locked->id,
                'prize_id' => $prize->id,
                'ticket_id' => $winner->id,
                'action' => 'draw',
                'user_id' => $request->user()->id,
                'created_at' => now(),
            ]);

            return [
                'raffle_id' => $locked->id,
                'public_token' => $locked->public_token,
                'prize_id' => $prize->id,
                'position' => (int) $prize->position,
                'prize_name' => $prize->name,
                'ticket_id' => (int) $winner->id,
                'ticket_number' => $winner->number === null ? null : (int) $winner->number,
                'winner_name' => $winner->winner_name,
            ];
        });

        // Fuera de la transaccion: el proyector, al recibir, pide el estado real.
        Realtime::raffleDrawn(
            $result['public_token'],
            $result['raffle_id'],
            $result['prize_id'],
            $result['position'],
            $result['prize_name'],
            $result['ticket_number'],
            $result['winner_name'],
        );

        return response()->json([
            'message' => 'Premio sorteado',
            'winner' => [
                'prize_id' => $result['prize_id'],
                'position' => $result['position'],
                'prize_name' => $result['prize_name'],
                'ticket_number' => $result['ticket_number'],
                'winner_name' => $result['winner_name'],
            ],
        ]);
    }

    /**
     * Vuelve a sortear: limpia el ganador del premio y deja registro. El
     * proximo draw elige de nuevo (y, si no se permiten repetidos, el ganador
     * anterior ya no esta en el pool porque el filtro lo excluye).
     */
    public function reset(Request $request, int $id, int $prizeId): JsonResponse
    {
        $raffle = $this->findScoped($request, $id);

        if (! $raffle) {
            return response()->json(['message' => 'Sorteo no encontrado'], 404);
        }

        $result = DB::transaction(function () use ($raffle, $prizeId, $request) {
            $locked = DB::table('tickets_raffles')->where('id', $raffle->id)->lockForUpdate()->first();

            $prize = DB::table('tickets_raffle_prizes')
                ->where('id', $prizeId)
                ->where('raffle_id', $locked->id)
                ->first();

            if (! $prize) {
                throw ValidationException::withMessages(['prize' => 'Premio no encontrado']);
            }

            $previous = $prize->winner_ticket_id;

            DB::table('tickets_raffle_prizes')->where('id', $prize->id)->update([
                'winner_ticket_id' => null,
                'drawn_at' => null,
                'drawn_by' => null,
                'updated_at' => now(),
            ]);

            DB::table('tickets_raffle_draws')->insert([
                'raffle_id' => $locked->id,
                'prize_id' => $prize->id,
                'ticket_id' => $previous,
                'action' => 'reset',
                'user_id' => $request->user()->id,
                'created_at' => now(),
            ]);

            return [
                'public_token' => $locked->public_token,
                'raffle_id' => $locked->id,
                'prize_id' => $prize->id,
                'position' => (int) $prize->position,
            ];
        });

        // Avisa al proyector para que borre el ganador en pantalla.
        Realtime::raffleDrawn(
            $result['public_token'],
            $result['raffle_id'],
            $result['prize_id'],
            $result['position'],
            null,
            null,
            null,
        );

        return response()->json(['message' => 'Premio reiniciado']);
    }

    /**
     * Estado completo para la ventana publica de proyeccion. Sin sesion: el
     * token del sorteo es la credencial. Muestra numero de entrada y nombre del
     * comprador de cada premio ya sorteado.
     */
    public function publicState(string $token): JsonResponse
    {
        $raffle = DB::table('tickets_raffles')->where('public_token', $token)->first();

        if (! $raffle) {
            return response()->json(['message' => 'Sorteo no encontrado'], 404);
        }

        return response()->json([
            'raffle' => [
                'name' => $raffle->name,
                'status' => $raffle->status,
                'token' => $raffle->public_token,
            ],
            'event' => DB::table('tickets_events')
                ->where('id', $raffle->event_id)
                ->first(['id', 'name']),
            'prizes' => $this->prizesWithWinners($raffle->id),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string,mixed>
     */
    private function rules(bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'event_id' => [$required, 'integer', 'exists:tickets_events,id'],
            'name' => [$required, 'string', 'max:150'],
            'status' => ['sometimes', 'in:draft,open,finished'],
            'number_from' => ['nullable', 'integer', 'min:1'],
            'number_to' => ['nullable', 'integer', 'min:1', 'gte:number_from'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => ['string', 'in:'.implode(',', self::PAYMENT_METHOD_VALUES)],
            'only_paid' => ['sometimes', 'boolean'],
            'include_used' => ['sometimes', 'boolean'],
            'include_cancelled' => ['sometimes', 'boolean'],
            'allow_repeat_winners' => ['sometimes', 'boolean'],
            'prizes' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'prizes.*.name' => ['nullable', 'string', 'max:150'],
            'prizes.*.description' => ['nullable', 'string'],
        ];
    }

    /**
     * Campos comunes de tickets_raffles normalizados para guardar.
     *
     * @param  array<string,mixed>  $validated
     * @return array<string,mixed>
     */
    private function fields(array $validated): array
    {
        $fields = [];

        if (array_key_exists('name', $validated)) {
            $fields['name'] = $validated['name'];
        }

        foreach (['number_from', 'number_to'] as $key) {
            if (array_key_exists($key, $validated)) {
                $fields[$key] = $validated[$key] === null ? null : (int) $validated[$key];
            }
        }

        $dates = DateInput::normalize($validated, ['date_from', 'date_to']);
        foreach (['date_from', 'date_to'] as $key) {
            if (array_key_exists($key, $validated)) {
                $fields[$key] = $dates[$key] ?? null;
            }
        }

        if (array_key_exists('payment_methods', $validated)) {
            $methods = array_values(array_unique($validated['payment_methods'] ?? []));
            $fields['payment_methods'] = $methods === [] ? null : json_encode($methods);
        }

        foreach (['only_paid', 'include_used', 'include_cancelled', 'allow_repeat_winners'] as $key) {
            if (array_key_exists($key, $validated)) {
                $fields[$key] = (bool) $validated[$key];
            }
        }

        return $fields;
    }

    /**
     * Crea/actualiza/borra premios para que la lista coincida con la recibida
     * (la posicion es el indice + 1). No deja borrar un premio ya adjudicado.
     *
     * @param  array<int,array<string,mixed>>  $prizes
     */
    private function syncPrizes(int $raffleId, array $prizes): void
    {
        $existing = DB::table('tickets_raffle_prizes')
            ->where('raffle_id', $raffleId)
            ->get()
            ->keyBy('position');

        $kept = [];

        foreach (array_values($prizes) as $i => $prize) {
            $position = $i + 1;
            $kept[] = $position;

            $data = [
                'name' => $prize['name'] ?? null,
                'description' => $prize['description'] ?? null,
                'updated_at' => now(),
            ];

            if ($existing->has($position)) {
                DB::table('tickets_raffle_prizes')
                    ->where('id', $existing[$position]->id)
                    ->update($data);

                continue;
            }

            DB::table('tickets_raffle_prizes')->insert([
                ...$data,
                'raffle_id' => $raffleId,
                'position' => $position,
                'created_at' => now(),
            ]);
        }

        foreach ($existing as $position => $row) {
            if (in_array($position, $kept, true)) {
                continue;
            }

            if ($row->winner_ticket_id !== null) {
                throw ValidationException::withMessages([
                    'prizes' => "No se puede quitar el premio N° {$position}: ya tiene ganador. Reinicialo primero.",
                ]);
            }

            DB::table('tickets_raffle_prizes')->where('id', $row->id)->delete();
        }
    }

    /**
     * Query de entradas que cumplen el filtro del sorteo, sin el limite de
     * "ganadores previos" aplicado aparte (lo agrega si no se permiten repetidos).
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function eligibleQuery(object $raffle)
    {
        $query = DB::table('tickets_tickets as t')
            ->join('tickets_orders as o', 't.order_id', '=', 'o.id')
            ->where('t.event_id', $raffle->event_id);

        // Solo pagas (default) o tambien las pendientes; nunca canceladas/expiradas.
        $orderStatuses = $raffle->only_paid ? ['paid'] : ['paid', 'pending'];
        $query->whereIn('o.status', $orderStatuses);

        $ticketStatuses = ['valid'];
        if ($raffle->include_used) {
            $ticketStatuses[] = 'used';
        }
        if ($raffle->include_cancelled) {
            $ticketStatuses[] = 'cancelled';
        }
        $query->whereIn('t.status', $ticketStatuses);

        if ($raffle->number_from !== null) {
            $query->where('t.number', '>=', (int) $raffle->number_from);
        }
        if ($raffle->number_to !== null) {
            $query->where('t.number', '<=', (int) $raffle->number_to);
        }

        if ($raffle->date_from !== null) {
            $query->whereRaw('COALESCE(o.paid_at, o.created_at) >= ?', [$raffle->date_from]);
        }
        if ($raffle->date_to !== null) {
            $query->whereRaw('COALESCE(o.paid_at, o.created_at) <= ?', [$raffle->date_to]);
        }

        $methods = json_decode($raffle->payment_methods ?? '[]', true) ?: [];
        if ($methods !== []) {
            $query->where(function ($sub) use ($methods) {
                foreach ($methods as $method) {
                    $parsed = PaymentMethodFilter::normalize($method);
                    if ($parsed === null) {
                        continue;
                    }
                    $sub->orWhere(function ($w) use ($parsed) {
                        PaymentMethodFilter::constrain($w, $parsed, 'o.payment_method', 'o.payment_variant');
                    });
                }
            });
        }

        if (! $raffle->allow_repeat_winners) {
            $previousWinners = DB::table('tickets_raffle_prizes')
                ->where('raffle_id', $raffle->id)
                ->whereNotNull('winner_ticket_id')
                ->pluck('winner_ticket_id');

            if ($previousWinners->isNotEmpty()) {
                $query->whereNotIn('t.id', $previousWinners);
            }
        }

        return $query;
    }

    /**
     * @return array<string,mixed>
     */
    private function rafflePayload(object $raffle): array
    {
        return [
            ...(array) $raffle,
            'payment_methods' => json_decode($raffle->payment_methods ?? '[]', true) ?: [],
            'event' => DB::table('tickets_events')->where('id', $raffle->event_id)->first(['id', 'name']),
            'prizes' => $this->prizesWithWinners($raffle->id),
            'eligible_count' => $this->eligibleQuery($raffle)->count(),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function prizesWithWinners(int $raffleId)
    {
        return DB::table('tickets_raffle_prizes as p')
            ->leftJoin('tickets_tickets as t', 'p.winner_ticket_id', '=', 't.id')
            ->leftJoin('tickets_orders as o', 't.order_id', '=', 'o.id')
            ->where('p.raffle_id', $raffleId)
            ->orderBy('p.position')
            ->get([
                'p.id', 'p.position', 'p.name', 'p.description',
                'p.winner_ticket_id', 'p.drawn_at',
                't.number as ticket_number',
                'o.buyer_name as winner_name',
            ]);
    }

    private function findScoped(Request $request, int $id): ?object
    {
        $raffle = DB::table('tickets_raffles')->where('id', $id)->first();

        if (! $raffle) {
            return null;
        }

        $user = $request->user();

        if ($user && ! $user->isStaffAdmin() && ! in_array((int) $raffle->event_id, $user->assignedEventIds(), true)) {
            return null;
        }

        return $raffle;
    }

    private function assertEventAllowed(Request $request, int $eventId): void
    {
        $user = $request->user();

        if ($user && ! $user->isStaffAdmin() && ! in_array($eventId, $user->assignedEventIds(), true)) {
            throw ValidationException::withMessages([
                'event_id' => 'No tenes acceso a ese evento',
            ]);
        }
    }

    /**
     * @return array<int,int>|null
     */
    private function scopeEventIds(Request $request, ?int $eventId): ?array
    {
        $user = $request->user();

        if (! $user || $user->isStaffAdmin()) {
            return $eventId ? [$eventId] : null;
        }

        $assigned = $user->assignedEventIds();

        if ($eventId) {
            return array_values(array_intersect([$eventId], $assigned));
        }

        return $assigned;
    }
}
