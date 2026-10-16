<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Modulo de sorteos.
 *
 * Cubre lo que puede romperse en silencio: el numero correlativo por evento
 * (base del rango "desde/hasta"), el filtro de elegibilidad, la eleccion del
 * ganador sin repetir y la auditoria del re-sorteo, ademas del acceso publico
 * del proyector por token.
 */
class RaffleTest extends TestCase
{
    use RefreshDatabase;

    private ?User $buyer = null;

    // ------------------------------------------------------------------ setup

    /**
     * Evento publicado con un tipo de entrada. Se crea con su propio admin para
     * no chocar con el indice unico de email si el test pide otro admin.
     *
     * @return array{0:User,1:int,2:int}
     */
    private function event(array $typeOverrides = []): array
    {
        $admin = $this->admin(['email' => 'admin-'.uniqid().'@test.local']);

        $eventId = $this->actingAs($admin)->postJson('/tickets-admin/events', [
            'name' => 'Festival '.uniqid(),
            'starts_at' => now()->addMonth()->toDateTimeString(),
            'status' => 'published',
        ])->assertCreated()->json('id');

        $typeId = $this->actingAs($admin)->postJson("/tickets-admin/events/{$eventId}/ticket-types", [
            'name' => 'General',
            'price' => 5000,
            'stock' => 100,
            'max_per_order' => 10,
            'payment_mode' => 'both',
            'max_installments' => 1,
            'enable' => true,
            ...$typeOverrides,
        ])->assertCreated()->json('id');

        return [$admin, $eventId, $typeId];
    }

    private function currentBuyer(): User
    {
        return $this->buyer ??= User::create([
            'name' => 'Comprador',
            'email' => 'comprador-raffle@test.local',
            'dni' => '30111222',
            'password' => 'secreto123',
            'is_admin' => false,
        ]);
    }

    /**
     * Vende entradas por el portal y marca la orden como pagada. Los tickets
     * quedan con su numero correlativo asignado por el servicio.
     */
    private function sell(int $eventId, int $typeId, int $qty = 1, array $overrides = []): array
    {
        $order = $this->actingAs($this->currentBuyer())->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => $qty]],
        ])->assertCreated()->json();

        DB::table('tickets_orders')->where('id', $order['order_id'])->update([
            'status' => 'paid',
            'payment_method' => $overrides['payment_method'] ?? 'efectivo',
            'payment_variant' => $overrides['payment_variant'] ?? null,
            'paid_at' => $overrides['paid_at'] ?? now(),
            'created_at' => $overrides['created_at'] ?? now(),
        ]);

        return $order;
    }

    /**
     * Crea un sorteo con los defaults y devuelve el id.
     */
    private function raffle(User $admin, int $eventId, array $overrides = []): int
    {
        return $this->actingAs($admin)->postJson('/tickets-admin/raffles', [
            'event_id' => $eventId,
            'name' => 'Sorteo '.uniqid(),
            'prizes' => [['name' => 'Premio 1', 'description' => 'El grande']],
            ...$overrides,
        ])->assertCreated()->json('id');
    }

    // ------------------------------------------------------------- numeracion

    public function test_el_numero_de_entrada_es_correlativo_por_evento(): void
    {
        [$admin, $eventId, $typeId] = $this->event();
        [$admin2, $eventId2, $typeId2] = $this->event();

        $this->sell($eventId, $typeId, 3);
        $this->sell($eventId, $typeId, 2);
        $this->sell($eventId2, $typeId2, 1);

        $this->assertSame(
            [1, 2, 3, 4, 5],
            DB::table('tickets_tickets')->where('event_id', $eventId)->orderBy('number')->pluck('number')->all()
        );

        // El segundo evento arranca su propio correlativo en 1.
        $this->assertSame(
            [1],
            DB::table('tickets_tickets')->where('event_id', $eventId2)->orderBy('number')->pluck('number')->all()
        );
    }

    public function test_la_numeracion_arranca_en_el_numero_configurado_y_se_muestra_con_ceros(): void
    {
        $admin = $this->admin(['email' => 'admin-'.uniqid().'@test.local']);

        $eventId = $this->actingAs($admin)->postJson('/tickets-admin/events', [
            'name' => 'Numerado '.uniqid(),
            'starts_at' => now()->addMonth()->toDateTimeString(),
            'status' => 'published',
            'ticket_number_start' => 300,
            'ticket_number_pad' => 7,
        ])->assertCreated()->json('id');

        $typeId = $this->actingAs($admin)->postJson("/tickets-admin/events/{$eventId}/ticket-types", [
            'name' => 'General',
            'price' => 5000,
            'stock' => 100,
            'max_per_order' => 10,
            'payment_mode' => 'both',
            'max_installments' => 1,
            'enable' => true,
        ])->assertCreated()->json('id');

        $this->sell($eventId, $typeId, 2);

        // Arranca en el numero configurado (no en 1) y sigue el correlativo.
        $this->assertSame(
            [300, 301],
            DB::table('tickets_tickets')->where('event_id', $eventId)->orderBy('number')->pluck('number')->all()
        );

        // El QR publico dibuja el numero con los 7 digitos configurados.
        $ticketId = DB::table('tickets_tickets')->where('event_id', $eventId)->orderBy('number')->value('id');
        $token = DB::table('tickets_orders')->where('event_id', $eventId)->value('public_token');
        $qr = $this->get("/tickets-portal/api/public/entry/{$token}/{$ticketId}/qr.svg");
        $qr->assertOk();
        $this->assertStringContainsString('N° 0000300', $qr->getContent());

        // El sorteo revela el numero formateado igual que el QR.
        $raffleId = $this->raffle($admin, $eventId);
        $prizeId = DB::table('tickets_raffle_prizes')->where('raffle_id', $raffleId)->value('id');
        $winner = $this->actingAs($admin)
            ->postJson("/tickets-admin/raffles/{$raffleId}/prizes/{$prizeId}/draw")
            ->assertOk()
            ->json('winner.ticket_number');

        $this->assertContains($winner, ['0000300', '0000301']);
    }

    public function test_la_numeracion_no_se_puede_cambiar_al_editar_el_evento(): void
    {
        $admin = $this->admin(['email' => 'admin-'.uniqid().'@test.local']);

        $eventId = $this->actingAs($admin)->postJson('/tickets-admin/events', [
            'name' => 'Fijo '.uniqid(),
            'status' => 'draft',
            'ticket_number_start' => 50,
            'ticket_number_pad' => 4,
        ])->assertCreated()->json('id');

        // El update ignora estos campos: la numeracion se fija al crear.
        $this->actingAs($admin)->putJson("/tickets-admin/events/{$eventId}", [
            'name' => 'Fijo editado',
            'ticket_number_start' => 1,
            'ticket_number_pad' => 1,
        ])->assertOk();

        $event = DB::table('tickets_events')->where('id', $eventId)->first();
        $this->assertSame(50, (int) $event->ticket_number_start);
        $this->assertSame(4, (int) $event->ticket_number_pad);
    }

    // ------------------------------------------------------------------- CRUD

    public function test_el_admin_crea_un_sorteo_con_sus_premios(): void
    {
        [$admin, $eventId] = $this->event();

        $id = $this->raffle($admin, $eventId, [
            'name' => 'Rifa principal',
            'prizes' => [
                ['name' => 'Primero', 'description' => 'Una moto'],
                ['name' => 'Segundo', 'description' => 'Un TV'],
            ],
        ]);

        $show = $this->actingAs($admin)->getJson("/tickets-admin/raffles/{$id}")->assertOk();

        $this->assertSame('Rifa principal', $show->json('name'));
        $this->assertCount(2, $show->json('prizes'));
        $this->assertSame([1, 2], array_column($show->json('prizes'), 'position'));
        $this->assertNotNull($show->json('public_token'));
    }

    public function test_el_administrativo_solo_ve_sus_eventos(): void
    {
        [$admin, $eventId] = $this->event();
        [$admin2, $eventId2] = $this->event();

        $mine = $this->raffle($admin, $eventId);
        $other = $this->raffle($admin2, $eventId2);

        $adminstrativo = $this->administrativo();
        $this->assignEvents($adminstrativo, [$eventId]);

        $list = $this->actingAs($adminstrativo)->getJson('/tickets-admin/raffles')->assertOk();

        $this->assertSame([$mine], array_column($list->json('data'), 'id'));

        // Un sorteo de otro evento no se puede ver ni tocar.
        $this->actingAs($adminstrativo)->getJson("/tickets-admin/raffles/{$other}")->assertNotFound();
        $this->actingAs($adminstrativo)->putJson("/tickets-admin/raffles/{$other}", ['name' => 'x'])->assertNotFound();
    }

    public function test_cajero_y_vendedor_no_acceden_a_sorteos(): void
    {
        [$admin, $eventId] = $this->event();
        $id = $this->raffle($admin, $eventId);

        $this->actingAs($this->cashier())->getJson('/tickets-admin/raffles')->assertForbidden();
        $this->actingAs($this->seller())->getJson("/tickets-admin/raffles/{$id}")->assertForbidden();
    }

    // ------------------------------------------------------------ elegibilidad

    public function test_la_elegibilidad_filtra_por_medio_de_pago(): void
    {
        [$admin, $eventId, $typeId] = $this->event();

        $this->sell($eventId, $typeId, 1, ['payment_method' => 'efectivo']);
        $this->sell($eventId, $typeId, 2, ['payment_method' => 'mercadopago']);
        $this->sell($eventId, $typeId, 1, ['payment_method' => 'multipago', 'payment_variant' => 'qr']);

        $id = $this->raffle($admin, $eventId, ['payment_methods' => ['mercadopago']]);

        $eligible = $this->actingAs($admin)->getJson("/tickets-admin/raffles/{$id}/eligible")->assertOk();

        $this->assertSame(2, $eligible->json('count'));
    }

    public function test_la_elegibilidad_filtra_por_rango_de_numeros(): void
    {
        [$admin, $eventId, $typeId] = $this->event();

        // Numeros 1..3 y 4..5.
        $this->sell($eventId, $typeId, 3);
        $this->sell($eventId, $typeId, 2);

        $id = $this->raffle($admin, $eventId, ['number_from' => 2, 'number_to' => 4]);

        $this->assertSame(
            3,
            $this->actingAs($admin)->getJson("/tickets-admin/raffles/{$id}/eligible")->json('count')
        );
    }

    public function test_la_elegibilidad_solo_toma_ordenes_pagas_por_defecto(): void
    {
        [$admin, $eventId, $typeId] = $this->event();

        // El sell() deja la orden pagada; esta queda pendiente a proposito.
        $pending = $this->actingAs($this->currentBuyer())->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => 1]],
        ])->assertCreated()->json();
        DB::table('tickets_orders')->where('id', $pending['order_id'])->update([
            'payment_method' => 'efectivo',
        ]);

        $this->sell($eventId, $typeId, 1);

        $id = $this->raffle($admin, $eventId);

        // Solo la paga cuenta; la pendiente no.
        $this->assertSame(
            1,
            $this->actingAs($admin)->getJson("/tickets-admin/raffles/{$id}/eligible")->json('count')
        );

        // Configurado para incluir no pagas, entran las dos.
        $this->actingAs($admin)->putJson("/tickets-admin/raffles/{$id}", ['only_paid' => false])->assertOk();

        $this->assertSame(
            2,
            $this->actingAs($admin)->getJson("/tickets-admin/raffles/{$id}/eligible")->json('count')
        );
    }

    // -------------------------------------------------------------- sorteo

    public function test_el_preview_de_elegibles_usa_los_filtros_sin_guardar(): void
    {
        [$admin, $eventId, $typeId] = $this->event();

        // Numeros 1..2 (efectivo) y 3..5 (mercadopago).
        $this->sell($eventId, $typeId, 2, ['payment_method' => 'efectivo']);
        $this->sell($eventId, $typeId, 3, ['payment_method' => 'mercadopago']);

        // Filtro por medio de pago, sin haber creado ningun sorteo.
        $preview = $this->actingAs($admin)->postJson('/tickets-admin/raffles/eligible-preview', [
            'event_id' => $eventId,
            'payment_methods' => ['efectivo'],
        ])->assertOk();
        $this->assertSame(2, $preview->json('count'));

        // Rango de numeros sobre todas las pagas.
        $preview = $this->actingAs($admin)->postJson('/tickets-admin/raffles/eligible-preview', [
            'event_id' => $eventId,
            'number_from' => 1,
            'number_to' => 4,
        ])->assertOk();
        $this->assertSame(4, $preview->json('count'));

        // Sin evento no hay universo que calcular.
        $this->actingAs($admin)->postJson('/tickets-admin/raffles/eligible-preview', [])
            ->assertStatus(422);
    }

    public function test_sortear_elige_una_entrada_y_registra_auditoria(): void
    {
        [$admin, $eventId, $typeId] = $this->event();
        $this->sell($eventId, $typeId, 4);

        $id = $this->raffle($admin, $eventId);
        $prizeId = DB::table('tickets_raffle_prizes')->where('raffle_id', $id)->value('id');

        $draw = $this->actingAs($admin)
            ->postJson("/tickets-admin/raffles/{$id}/prizes/{$prizeId}/draw")
            ->assertOk();

        $winnerNumber = $draw->json('winner.ticket_number');
        $this->assertContains((int) $winnerNumber, [1, 2, 3, 4]);

        $this->assertSame(
            DB::table('tickets_tickets')->where('event_id', $eventId)->where('number', (int) $winnerNumber)->value('id'),
            DB::table('tickets_raffle_prizes')->where('id', $prizeId)->value('winner_ticket_id')
        );

        $log = DB::table('tickets_raffle_draws')->where('prize_id', $prizeId)->first();
        $this->assertSame('draw', $log->action);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_no_repite_ganador_si_no_se_permite(): void
    {
        [$admin, $eventId, $typeId] = $this->event();
        $this->sell($eventId, $typeId, 2);

        $id = $this->raffle($admin, $eventId, [
            'allow_repeat_winners' => false,
            'prizes' => [['name' => 'A'], ['name' => 'B']],
        ]);

        $prizes = DB::table('tickets_raffle_prizes')->where('raffle_id', $id)->orderBy('position')->pluck('id')->all();

        $first = $this->actingAs($admin)->postJson("/tickets-admin/raffles/{$id}/prizes/{$prizes[0]}/draw")->assertOk();
        $second = $this->actingAs($admin)->postJson("/tickets-admin/raffles/{$id}/prizes/{$prizes[1]}/draw")->assertOk();

        $this->assertNotSame($first->json('winner.ticket_number'), $second->json('winner.ticket_number'));

        // Ya no quedan entradas distintas: un tercer sorteo sobre este pool de 2 falla.
        DB::table('tickets_raffle_prizes')->insert([
            'raffle_id' => $id, 'position' => 3, 'name' => 'C',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $thirdPrize = DB::table('tickets_raffle_prizes')->where('raffle_id', $id)->where('position', 3)->value('id');

        $this->actingAs($admin)
            ->postJson("/tickets-admin/raffles/{$id}/prizes/{$thirdPrize}/draw")
            ->assertStatus(422);
    }

    public function test_permite_repetir_ganador_si_se_configura(): void
    {
        [$admin, $eventId, $typeId] = $this->event();
        $this->sell($eventId, $typeId, 1);

        $id = $this->raffle($admin, $eventId, [
            'allow_repeat_winners' => true,
            'prizes' => [['name' => 'A'], ['name' => 'B']],
        ]);

        $prizes = DB::table('tickets_raffle_prizes')->where('raffle_id', $id)->orderBy('position')->pluck('id')->all();

        $first = $this->actingAs($admin)->postJson("/tickets-admin/raffles/{$id}/prizes/{$prizes[0]}/draw")->assertOk();
        // Con una sola entrada y repeticion permitida, el segundo tambien sale ganador.
        $second = $this->actingAs($admin)->postJson("/tickets-admin/raffles/{$id}/prizes/{$prizes[1]}/draw")->assertOk();

        $this->assertSame($first->json('winner.ticket_number'), $second->json('winner.ticket_number'));
    }

    public function test_sortear_sin_elegibles_devuelve_422(): void
    {
        [$admin, $eventId] = $this->event();
        $id = $this->raffle($admin, $eventId);
        $prizeId = DB::table('tickets_raffle_prizes')->where('raffle_id', $id)->value('id');

        $this->actingAs($admin)
            ->postJson("/tickets-admin/raffles/{$id}/prizes/{$prizeId}/draw")
            ->assertStatus(422);
    }

    public function test_reset_limpia_el_ganador_y_audita(): void
    {
        [$admin, $eventId, $typeId] = $this->event();
        $this->sell($eventId, $typeId, 1);

        $id = $this->raffle($admin, $eventId);
        $prizeId = DB::table('tickets_raffle_prizes')->where('raffle_id', $id)->value('id');

        $this->actingAs($admin)->postJson("/tickets-admin/raffles/{$id}/prizes/{$prizeId}/draw")->assertOk();
        $this->actingAs($admin)->postJson("/tickets-admin/raffles/{$id}/prizes/{$prizeId}/reset")->assertOk();

        $prize = DB::table('tickets_raffle_prizes')->where('id', $prizeId)->first();
        $this->assertNull($prize->winner_ticket_id);
        $this->assertNull($prize->drawn_at);

        $this->assertSame(
            'reset',
            DB::table('tickets_raffle_draws')->where('prize_id', $prizeId)->where('action', 'reset')->value('action')
        );
    }

    public function test_no_se_puede_quitar_un_premio_con_ganador(): void
    {
        [$admin, $eventId, $typeId] = $this->event();
        $this->sell($eventId, $typeId, 1);

        $id = $this->raffle($admin, $eventId, [
            'prizes' => [['name' => 'A'], ['name' => 'B']],
        ]);

        // Se adjudica el premio de la posicion 2.
        $secondPrize = DB::table('tickets_raffle_prizes')
            ->where('raffle_id', $id)->where('position', 2)->value('id');

        $this->actingAs($admin)->postJson("/tickets-admin/raffles/{$id}/prizes/{$secondPrize}/draw")->assertOk();

        // Querer dejar un solo premio borraria la posicion 2, que ya tiene ganador.
        $this->actingAs($admin)->putJson("/tickets-admin/raffles/{$id}", [
            'prizes' => [['name' => 'Otro']],
        ])->assertStatus(422);
    }

    // --------------------------------------------------------------- publico

    public function test_el_estado_publico_responde_sin_sesion(): void
    {
        [$admin, $eventId, $typeId] = $this->event();
        $this->sell($eventId, $typeId, 1);

        $id = $this->raffle($admin, $eventId, ['name' => 'Sorteo publico']);
        $token = DB::table('tickets_raffles')->where('id', $id)->value('public_token');
        $prizeId = DB::table('tickets_raffle_prizes')->where('raffle_id', $id)->value('id');

        $this->actingAs($admin)->postJson("/tickets-admin/raffles/{$id}/prizes/{$prizeId}/draw")->assertOk();

        // Sin sesion (auth()->logout y pedir como invitado).
        $this->app['auth']->logout();

        $state = $this->getJson("/tickets-portal/api/public/raffle/{$token}")->assertOk();

        $this->assertSame('Sorteo publico', $state->json('raffle.name'));
        $this->assertSame('1', $state->json('prizes.0.ticket_number'));
        $this->assertNotNull($state->json('prizes.0.winner_name'));
    }

    public function test_token_de_sorteo_invalido_devuelve_404(): void
    {
        $this->getJson('/tickets-portal/api/public/raffle/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }
}
