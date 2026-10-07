<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\QrPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Recorre el camino completo: el panel crea el evento, el comprador compra, el
 * webhook de MercadoPago confirma el pago y el escaner valida el QR.
 *
 * Sirve como red de seguridad de los cambios de esta app, que no trae Eloquent
 * para Tickets y por eso depende de nombres de tabla y columna exactos.
 */
class PurchaseFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El navegador manda las fechas como ISO 8601 con Z y milisegundos, porque
     * <input type="datetime-local"> pasa por Date.toISOString(). La columna es
     * datetime pelada y MySQL no parsea eso: sin normalizar, el alta del evento
     * moria con SQLSTATE[22007].
     *
     * Y la conversion no es cosmetica. La app corre en America/Argentina/
     * Buenos_Aires (UTC-3), asi que un evento de las 20:00 llega como 23:00Z y
     * tiene que terminar guardado a las 20:00.
     */
    public function test_las_fechas_del_navegador_se_guardan_en_la_hora_local(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/tickets-admin/events', [
            'name' => 'Fiesta de la Sal',
            'starts_at' => '2026-10-30T23:00:00.000Z',
            'ends_at' => '2026-10-31T03:00:00.000Z',
            'status' => 'published',
        ])->assertCreated();

        $event = DB::table('tickets_events')->where('slug', 'fiesta-de-la-sal')->first();

        // 23:00 UTC son las 20:00 de Buenos Aires; 03:00 del dia 31 son las
        // 00:00 del mismo dia 31 local.
        $this->assertSame('2026-10-30 20:00:00', $event->starts_at);
        $this->assertSame('2026-10-31 00:00:00', $event->ends_at);

        // Y al editar pasa lo mismo.
        $this->actingAs($admin)->putJson("/tickets-admin/events/{$event->id}", [
            'starts_at' => '2026-11-01T15:30:00.000Z',
        ])->assertOk();

        $this->assertSame('2026-11-01 12:30:00', DB::table('tickets_events')->where('id', $event->id)->value('starts_at'));
    }

    /**
     * Un datetime ya plano se interpreta como hora local, sin corrimiento.
     * Es lo que llega si alguien carga datos por SQL o por un importador.
     */
    public function test_un_datetime_plano_se_guarda_sin_correrlo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/tickets-admin/events', [
            'name' => 'Importado',
            'starts_at' => '2026-10-30 20:00:00',
            'status' => 'draft',
        ])->assertCreated();

        $this->assertSame(
            '2026-10-30 20:00:00',
            DB::table('tickets_events')->where('slug', 'importado')->value('starts_at')
        );
    }

    /**
     * Lo mismo en publicidad y en tipos de entrada: mismo tipo de columna,
     * mismo input datetime-local en el formulario.
     */
    public function test_las_fechas_de_publicidad_y_tipos_tambien_se_normalizan(): void
    {
        // publishEvent() ya devuelve su propio admin: pedir otro con el mismo
        // email chocaria contra el indice unico de users.
        [$admin, $eventId] = $this->publishEvent();

        $ad = $this->actingAs($admin)->postJson('/tickets-admin/ads', [
            'event_id' => $eventId,
            'name' => 'Banner',
            'image_path' => '/uploads/x.jpg',
            'position' => 'top',
            'start_at' => '2026-10-30T23:00:00.000Z',
            'end_at' => '2026-11-02T23:00:00.000Z',
        ]);

        $ad->assertCreated();
        $row = DB::table('tickets_ads')->where('id', $ad->json('id'))->first();
        $this->assertSame('2026-10-30 20:00:00', $row->start_at);
        $this->assertSame('2026-11-02 20:00:00', $row->end_at);

        $type = $this->actingAs($admin)->postJson("/tickets-admin/events/{$eventId}/ticket-types", [
            'name' => 'General con venta',
            'price' => 5000,
            'stock' => 10,
            'max_per_order' => 4,
            'payment_mode' => 'both',
            'max_installments' => 6,
            'sale_start_at' => '2026-10-30T23:00:00.000Z',
            'sale_end_at' => '2026-11-01T23:00:00.000Z',
        ]);

        $type->assertCreated();
        $typeRow = DB::table('tickets_event_ticket_types')->where('id', $type->json('id'))->first();
        $this->assertSame('2026-10-30 20:00:00', $typeRow->sale_start_at);
        $this->assertSame('2026-11-01 20:00:00', $typeRow->sale_end_at);
    }

    private function publishEvent(array $typeOverrides = []): array
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/tickets-admin/events', [
            'name' => 'Gran Festival',
            'description' => 'Una noche entera',
            'starts_at' => now()->addMonth()->toDateTimeString(),
            'location' => 'Predio 1',
            'capacity' => 100,
            'status' => 'published',
        ]);

        if ($response->status() !== 201) {
            $this->fail('Alta de evento: '.$response->status().' '.$response->getContent());
        }

        $response->assertCreated();

        $eventId = $response->json('id');

        $type = $this->actingAs($admin)->postJson("/tickets-admin/events/{$eventId}/ticket-types", [
            'name' => 'General',
            'price' => 5000,
            'stock' => 10,
            'max_per_order' => 4,
            'payment_mode' => 'both',
            'max_installments' => 6,
            'enable' => true,
            ...$typeOverrides,
        ]);

        $type->assertCreated();

        return [$admin, $eventId, $type->json('id')];
    }

    /**
     * El login es UNO: un solo campo y el backend decide. Con el email de un
     * administrador tiene que entrar al panel; con el DNI de un comprador, al
     * portal. Si el usuario tiene que elegir el modo, un operador puede
     * quedarse trabado en el portal por escribir su email donde pidia el DNI.
     */
    public function test_login_unico_manda_al_admin_y_al_comprador_segun_corresponda(): void
    {
        $this->admin();

        // Admin por email.
        $admin = $this->postJson('/tickets-auth/login', [
            'identifier' => 'admin@test.local',
            'password' => 'secreto123',
        ]);

        $admin->assertOk();
        $this->assertTrue($admin->json('is_admin'));
        $this->assertTrue($admin->json('user.is_admin'));

        $this->postJson('/tickets-auth/logout');

        // Comprador por DNI, escrito con puntos como lo escribe la gente.
        $this->postJson('/tickets-portal/api/auth/register', [
            'name' => 'Ana Diaz',
            'email' => 'ana@test.local',
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
            'dni' => '29.923.360',
            'phone' => '1133334444',
            'consent' => true,
        ])->assertCreated();

        $this->postJson('/tickets-auth/logout');

        $buyer = $this->postJson('/tickets-auth/login', [
            'identifier' => '29.923.360',
            'password' => 'secreto123',
        ]);

        $buyer->assertOk();
        $this->assertFalse($buyer->json('is_admin'));
        $this->assertSame('29923360', $buyer->json('user.dni'));

        // Y el comprador no entra al panel: /auth/me dice que hay sesion
        // (la hay), lo que no puede es pasar el middleware del panel.
        $this->getJson('/tickets-admin/dashboard')->assertForbidden();
    }

    /**
     * El mismo mensaje para "no existe" y para "contraseña incorrecta": si
     * difieren, el login sirve para averiguar que emails o DNI estan dados de
     * alta.
     */
    public function test_login_unico_no_revela_si_el_usuario_existe(): void
    {
        $this->admin();

        $this->postJson('/tickets-auth/login', [
            'identifier' => 'nadie@test.local',
            'password' => 'secreto123',
        ])->assertUnauthorized();

        $wrong = $this->postJson('/tickets-auth/login', [
            'identifier' => 'admin@test.local',
            'password' => 'contrasena-mala',
        ])->assertUnauthorized();

        $unknown = $this->postJson('/tickets-auth/login', [
            'identifier' => 'nadie@test.local',
            'password' => 'contrasena-mala',
        ])->assertUnauthorized();

        $this->assertSame($wrong->json('message'), $unknown->json('message'));
    }

    /**
     * Un usuario desactivado no entra por el login unico. El mensaje si lo dice
     * porque el que intenta entrar es el usuario mismo.
     */
    public function test_login_unico_rechaza_un_usuario_deshabilitado(): void
    {
        $admin = $this->admin();

        User::whereKey($admin->id)->update(['enable' => false]);

        $this->postJson('/tickets-auth/login', [
            'identifier' => 'admin@test.local',
            'password' => 'secreto123',
        ])->assertForbidden();

        $this->assertFalse($this->getJson('/tickets-admin/auth/me')->json('authenticated'));
    }

    /**
     * El admin con contrasena temporal entra al panel a una pantalla donde lo
     * unico que puede hacer es cambiarla: el middleware responde 428 a todo lo
     * demas. Si /auth/me no devuelve el flag, un F5 lo deja en una pantalla
     * bloqueada sin salida.
     */
    public function test_el_admin_con_contrasena_temporal_solo_puede_cambiarla(): void
    {
        $admin = $this->admin(['must_change_password' => true]);

        $this->actingAs($admin);

        $me = $this->getJson('/tickets-admin/auth/me');

        $me->assertOk();
        $this->assertTrue($me->json('user.must_change_password'));

        // Todo el panel bloqueado con 428...
        $this->getJson('/tickets-admin/dashboard')->assertStatus(428);
        $this->getJson('/tickets-admin/events')->assertStatus(428);
        $this->getJson('/tickets-admin/config')->assertStatus(428);

        // ...menos el endpoint que resuelve el cambio.
        $changed = $this->postJson('/tickets-admin/auth/change-password', [
            'current_password' => 'secreto123',
            'password' => 'una-de-verdad-9',
            'password_confirmation' => 'una-de-verdad-9',
        ]);

        $changed->assertOk();

        // Y despues el panel se abre solo.
        $this->assertFalse($this->getJson('/tickets-admin/auth/me')->json('user.must_change_password'));
        $this->getJson('/tickets-admin/dashboard')->assertOk();

        // Con la vieja no se puede volver a cambiar.
        $this->postJson('/tickets-admin/auth/change-password', [
            'current_password' => 'secreto123',
            'password' => 'otra-de-verdad-9',
            'password_confirmation' => 'otra-de-verdad-9',
        ])->assertStatus(422);
    }

    /**
     * Los errores de validacion tienen que volver como JSON. Si vuelven como un
     * 302 a "/", el frontend recibe HTML donde espera el 422 con los errores por
     * campo y el formulario no muestra nada util.
     */
    public function test_el_login_unico_devuelve_json_en_los_errores_de_validacion(): void
    {
        $this->postJson('/tickets-auth/login', [
            'identifier' => '',
            'password' => 'corto',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['identifier', 'password']]);

        // Y en castellano, no en inglés. El campo se rotula DNI en la pantalla,
        // asi que el error habla de DNI y no de "email o DNI".
        $this->assertStringContainsString(
            'tu DNI',
            $this->postJson('/tickets-auth/login', ['identifier' => '', 'password' => 'corto'])
                ->json('errors.identifier.0')
        );
    }

    public function test_comprador_se_registra_con_dni_normalizado(): void
    {
        $response = $this->postJson('/tickets-portal/api/auth/register', [
            'name' => 'Ana Diaz',
            'email' => 'ana@test.local',
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
            'dni' => '29.923.360',
            'phone' => '1133334444',
            'consent' => true,
        ]);

        $response->assertCreated();

        // El DNI se guarda sin puntos, que es como lo busca el login.
        $this->assertSame('29923360', $response->json('user.dni'));
        $this->assertSame('29923360', DB::table('users')->value('dni'));

        // Y el login funciona escribiendo el DNI con formato.
        $this->postJson('/tickets-portal/api/auth/login', [
            'dni' => '29.923.360',
            'password' => 'secreto123',
        ])->assertOk();
    }

    public function test_no_se_puede_registrar_dni_duplicado(): void
    {
        $this->buyer(['dni' => '29923360']);

        $this->postJson('/tickets-portal/api/auth/register', [
            'name' => 'Otra',
            'email' => 'otra@test.local',
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
            'dni' => '29923360',
            'consent' => true,
        ])->assertStatus(422);
    }

    public function test_comprador_crea_orden_y_reserva_stock(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent();
        $buyer = $this->buyer();

        $response = $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [
                ['ticket_type_id' => $typeId, 'qty' => 2],
            ],
        ]);

        $response->assertCreated();
        $this->assertEqualsWithDelta(10000.0, $response->json('total'), 0.01);
        $this->assertSame(2, $response->json('ticket_count'));
        $this->assertNotEmpty($response->json('public_token'));

        // El stock se descuenta al crear la orden, no al pagar: es una reserva.
        $this->assertSame(2, (int) DB::table('tickets_event_ticket_types')->where('id', $typeId)->value('sold_count'));

        // Y se generan los dos boletos, cada uno con su QR firmado.
        $tickets = DB::table('tickets_tickets')->where('order_id', $response->json('order_id'))->get();
        $this->assertCount(2, $tickets);

        foreach ($tickets as $ticket) {
            $this->assertNotEmpty($ticket->qr_payload);
            $this->assertSame('valid', $ticket->status);
        }
    }

    public function test_no_se_puede_exceder_el_maximo_por_compra(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent(['max_per_order' => 2]);
        $buyer = $this->buyer();

        $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => 3]],
        ])->assertStatus(422);
    }

    /**
     * Regresion: en una instalacion nueva no hay fila qr_secret. El primer QR
     * emitido tiene que firmar igual, con el secreto recien creado, y el
     * escaner tiene que aceptarlo. Antes se firmaba con cadena vacia y el
     * boleto quedaba invalido para siempre, sin forma de recuperarlo.
     */
    public function test_primer_qr_se_firma_con_el_secreto_autogenerado(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent();
        $buyer = $this->buyer();

        // El escenario que se rompe: todavia no existe qr_secret.
        $this->assertSame(0, DB::table('tickets_configs')->where('name', 'qr_secret')->count());

        $order = $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => 1]],
        ])->assertCreated()->json();

        // Crear la orden dejo un unico qr_secret, no una fila por boleto.
        $secrets = DB::table('tickets_configs')->where('name', 'qr_secret')->get();
        $this->assertCount(1, $secrets);
        $this->assertNotSame('', $secrets->first()->value);

        $ticket = DB::table('tickets_tickets')->where('order_id', $order['order_id'])->first();

        // El QR verifica contra ese mismo secreto.
        $verified = QrPayload::verify($ticket->qr_payload, $secrets->first()->value);
        $this->assertNotNull($verified);
        $this->assertSame($ticket->uuid, $verified['uuid']);

        // Y el escaner lo acepta de punta a punta, sin pedir configuracion.
        DB::table('tickets_orders')->where('id', $order['order_id'])->update(['status' => 'paid']);

        $scan = $this->actingAs($admin)->postJson('/tickets-admin/scans', [
            'qr_payload' => $ticket->qr_payload,
            'event_id' => $eventId,
        ])->assertOk()->json();

        $this->assertSame('valid', $scan['result']);
    }

    /**
     * El indice unico sobre tickets_configs.name es lo que impide que dos
     * secretos distintos convivan y dejen boletos a medio firmar.
     */
    public function test_qr_secret_no_se_duplica(): void
    {
        $first = QrPayload::secret();
        $second = QrPayload::secret();

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('tickets_configs')->where('name', 'qr_secret')->count());
    }

    public function test_no_se_puede_superar_el_stock(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent(['stock' => 1]);
        $buyer = $this->buyer();

        $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => 2]],
        ])->assertStatus(422);
    }

    public function test_cuotas_no_se_piden_sobre_tipo_que_no_las_admite(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent(['payment_mode' => 'single', 'max_installments' => 1]);
        $buyer = $this->buyer();

        $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => 1]],
            'payment_mode' => 'installments',
            'installment_count' => 3,
        ])->assertStatus(422);
    }

    /**
 * El webhook sin mp_access_token no hace nada a proposito: sin token no puede
 * confirmar el pago contra la API de MercadoPago, y dar por pagado un payload
 * que no se pudo verificar seria peor que ignorarlo.
 */
private function fakeMercadoPagoToken(): void
{
    DB::table('tickets_configs')->updateOrInsert(
        ['name' => 'mp_access_token'],
        [
            'value' => 'APP_USR-token-de-prueba',
            'type' => 'text',
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );
}

public function test_webhook_sin_token_configurado_no_confirma_el_pago(): void
{
    [$admin, $eventId, $typeId] = $this->publishEvent();
    $buyer = $this->buyer();

    $order = $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
        'event_id' => $eventId,
        'items' => [['ticket_type_id' => $typeId, 'qty' => 1]],
    ])->json();

    Http::fake();

    // Sin token configurado responde ok para que MercadoPago no reintente en
    // bucle, pero la orden sigue pendiente.
    $this->postJson('/tickets/mp/webhook', [
        'type' => 'payment',
        'data' => ['id' => '555'],
    ])->assertOk();

    $this->assertSame('pending', DB::table('tickets_orders')->where('id', $order['order_id'])->value('status'));
}

public function test_webhook_marca_la_orden_pagada_y_es_idempotente(): void
{
    [$admin, $eventId, $typeId] = $this->publishEvent();
    $buyer = $this->buyer();

    $order = $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
        'event_id' => $eventId,
        'items' => [['ticket_type_id' => $typeId, 'qty' => 1]],
    ])->json();

    $this->fakeMercadoPagoToken();

        // La firma solo se exige si hay mp_webhook_secret configurado.
        Http::fake([
            'api.mercadopago.com/v1/payments/*' => Http::response([
                'status' => 'approved',
                'external_reference' => 'ENTRADAS-TICKET-'.$order['order_id'],
                'transaction_details' => ['net_received_amount' => 4800.5],
                'transaction_amount' => 5000,
            ]),
        ]);

        $payload = [
            'type' => 'payment',
            'topic' => 'payment',
            'data' => ['id' => '999888777'],
        ];

        $this->postJson('/tickets/mp/webhook', $payload)->assertOk();

        $row = DB::table('tickets_orders')->where('id', $order['order_id'])->first();
        $this->assertSame('paid', $row->status);
        $this->assertSame('999888777', $row->mp_payment_id);
        $this->assertNotNull($row->paid_at);

        // Reentrega: el estado no cambia ni se pisa paid_at.
        $paidAt = $row->paid_at;
        $this->postJson('/tickets/mp/webhook', $payload)->assertOk();
        $this->assertSame($paidAt, DB::table('tickets_orders')->where('id', $order['order_id'])->value('paid_at'));
    }

    public function test_webhook_rechaza_pago_no_aprobado(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent();
        $buyer = $this->buyer();

        $order = $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => 1]],
        ])->json();

        $this->fakeMercadoPagoToken();

        Http::fake([
            'api.mercadopago.com/v1/payments/*' => Http::response([
                'status' => 'pending',
                'external_reference' => 'ENTRADAS-TICKET-'.$order['order_id'],
            ]),
        ]);

        $this->postJson('/tickets/mp/webhook', [
            'type' => 'payment',
            'data' => ['id' => '123'],
        ])->assertOk();

        $this->assertSame('pending', DB::table('tickets_orders')->where('id', $order['order_id'])->value('status'));
    }

    public function test_cancelar_orden_devuelve_el_stock(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent();
        $buyer = $this->buyer();

        $order = $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => 3]],
        ])->json();

        $this->actingAs($buyer)
            ->postJson("/tickets-portal/api/orders/{$order['order_id']}/cancel")
            ->assertOk();

        $this->assertSame(0, (int) DB::table('tickets_event_ticket_types')->where('id', $typeId)->value('sold_count'));
        $this->assertSame('cancelled', DB::table('tickets_orders')->where('id', $order['order_id'])->value('status'));

        // Los boletos quedan anulados, nunca borrados: son el historial de la compra.
        $this->assertSame(0, DB::table('tickets_tickets')->where('order_id', $order['order_id'])->where('status', 'valid')->count());
        $this->assertSame(3, DB::table('tickets_tickets')->where('order_id', $order['order_id'])->count());
    }

    public function test_escaneo_valida_el_qr_y_lo_marca_usado(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent();
        $buyer = $this->buyer();

        $secret = Str::random(48);
        DB::table('tickets_configs')->updateOrInsert(
            ['name' => 'qr_secret'],
            ['value' => $secret, 'type' => 'text', 'created_at' => now(), 'updated_at' => now()]
        );

        $order = $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => 1]],
        ])->json();

        DB::table('tickets_orders')->where('id', $order['order_id'])->update(['status' => 'paid']);

        $ticket = DB::table('tickets_tickets')->where('order_id', $order['order_id'])->first();
        $payload = QrPayload::make($ticket->uuid, $secret);

        $first = $this->actingAs($admin)->postJson('/tickets-admin/scans', [
            'qr_payload' => $payload,
            'event_id' => $eventId,
        ]);

        $first->assertOk();
        $this->assertSame('valid', $first->json('result'));
        $this->assertSame('used', DB::table('tickets_tickets')->where('id', $ticket->id)->value('status'));

        // Segundo escaneo del mismo QR: la puerta tiene que ver que ya se uso.
        $second = $this->actingAs($admin)->postJson('/tickets-admin/scans', [
            'qr_payload' => $payload,
            'event_id' => $eventId,
        ]);

        $second->assertOk();
        $this->assertSame('used', $second->json('result'));
    }

    public function test_qr_adulterado_se_rechaza(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent();

        $secret = Str::random(48);
        DB::table('tickets_configs')->updateOrInsert(
            ['name' => 'qr_secret'],
            ['value' => $secret, 'type' => 'text', 'created_at' => now(), 'updated_at' => now()]
        );

        $response = $this->actingAs($admin)->postJson('/tickets-admin/scans', [
            'qr_payload' => json_encode(['uuid' => Str::uuid()->toString(), 's' => 'firma-falsa']),
            'event_id' => $eventId,
        ]);

        $response->assertOk();
        $this->assertSame('invalid', $response->json('result'));
    }

    public function test_comprador_no_entra_al_panel(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($buyer)->getJson('/tickets-admin/dashboard')->assertStatus(403);
        $this->actingAs($buyer)->getJson('/tickets-admin/events')->assertStatus(403);
    }

    /**
     * El cajero escanea y entrega pulseras, y no toca nada mas.
     *
     * Es el caso de la puerta del evento: entra al panel porque tiene que
     * escanear, pero no puede tocar eventos, ordenes, compradores,
     * estadisticas, publicidad ni configuracion.
     */
    public function test_el_cajero_solo_escanea_y_no_toca_la_administracion(): void
    {
        [$admin, $eventId] = $this->publishEvent();
        $cashier = $this->cashier();

        $this->actingAs($cashier);

        // --- Lo que si puede ---
        $this->getJson('/tickets-admin/events?all=1&status=published')->assertOk();
        $this->getJson('/tickets-admin/scans')->assertOk();

        $me = $this->getJson('/tickets-admin/auth/me');
        $me->assertOk();
        $this->assertSame('cajero', $me->json('user.role'));
        // El menu del panel se arma con esta lista, asi que no puede ofrecer
        // secciones que despues el servidor niegue.
        $this->assertSame(['scanner', 'scans', 'password'], $me->json('user.routes'));

        // --- Lo que no puede ---
        $this->getJson('/tickets-admin/dashboard')->assertStatus(403);
        $this->getJson('/tickets-admin/statistics')->assertStatus(403);
        $this->getJson('/tickets-admin/orders')->assertStatus(403);
        $this->getJson('/tickets-admin/tickets')->assertStatus(403);
        $this->getJson('/tickets-admin/users')->assertStatus(403);
        $this->getJson('/tickets-admin/users/buyers')->assertStatus(403);
        $this->getJson('/tickets-admin/ads')->assertStatus(403);
        $this->getJson('/tickets-admin/config')->assertStatus(403);

        // Tampoco puede crear ni editar un evento, ni aunque solo mande el
        // nombre: el listado es de lectura, el resto es de administracion.
        $this->postJson('/tickets-admin/events', ['name' => 'Inventado', 'status' => 'draft'])->assertStatus(403);
        $this->putJson("/tickets-admin/events/{$eventId}", ['name' => 'Renombrado'])->assertStatus(403);
        $this->deleteJson("/tickets-admin/events/{$eventId}")->assertStatus(403);
        $this->postJson('/tickets-admin/users', [
            'name' => 'Nuevo',
            'email' => 'nuevo@test.local',
            'password' => 'secreto123',
        ])->assertStatus(403);

        // Y el admin sigue entrando a todo lo suyo.
        $this->actingAs($admin)->getJson('/tickets-admin/dashboard')->assertOk();
        $this->actingAs($admin)->getJson('/tickets-admin/users')->assertOk();
    }

    /**
     * El cajero entra al panel desde el login unico, no al portal.
     */
    public function test_el_cajero_entra_al_panel_desde_el_login_unico(): void
    {
        $this->cashier(['email' => 'cajero@test.local']);

        $response = $this->postJson('/tickets-auth/login', [
            'identifier' => 'cajero@test.local',
            'password' => 'secreto123',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('is_admin'));
        $this->assertSame('cajero', $response->json('user.role'));
        $this->assertSame(['scanner', 'scans', 'password'], $response->json('user.routes'));

        // La sesion que abrio el login tiene que servir para la puerta.
        $this->getJson('/tickets-admin/scans')->assertOk();
    }

    /**
     * Un operador cargado antes de que existiera el rol tiene role NULL y tiene
     * que seguir viendo el panel entero. Si se lo TOMARA como cajero, un
     * administrador de produccion perderia el acceso de un dia para otro.
     */
    public function test_un_operador_sin_rol_define_sigue_siendo_administrador(): void
    {
        $legacy = $this->admin(['email' => 'viejo@test.local']);
        $legacy->forceFill(['role' => null])->save();

        $this->assertTrue($legacy->fresh()->isStaffAdmin());
        $this->assertFalse($legacy->fresh()->isCashier());

        $this->actingAs($legacy);
        $this->getJson('/tickets-admin/dashboard')->assertOk();
        $this->getJson('/tickets-admin/config')->assertOk();
        $this->assertSame(['*'], $this->getJson('/tickets-admin/auth/me')->json('user.routes'));
    }

    /**
     * El cajero tambien queda bloqueado por la contrasena temporal.
     */
    public function test_el_cajero_tambien_tiene_que_cambiar_la_contrasena_temporal(): void
    {
        $cashier = $this->cashier(['must_change_password' => true]);

        $this->actingAs($cashier);

        $this->getJson('/tickets-admin/dashboard')->assertStatus(428);
        $this->getJson('/tickets-admin/scans')->assertStatus(428);
        $this->assertTrue($this->getJson('/tickets-admin/auth/me')->json('user.must_change_password'));

        $this->postJson('/tickets-admin/auth/change-password', [
            'current_password' => 'secreto123',
            'password' => 'una-de-verdad-9',
            'password_confirmation' => 'una-de-verdad-9',
        ])->assertOk();

        $this->getJson('/tickets-admin/scans')->assertOk();
    }

    /**
     * El unico admin de verdad no se puede quedar sin panel por error.
     *
     * El conteo mira solo los role=admin: un cajero en turno no sirve de
     * respaldo porque no puede reconfigurar MercadoPago ni dar de alta a otro.
     */
    public function test_no_se_puede_desactivar_al_unico_administrador_con_un_cajero_activo(): void
    {
        $admin = $this->admin();
        $this->cashier();

        $this->actingAs($admin)
            ->putJson("/tickets-admin/users/{$admin->id}", ['enable' => false])
            ->assertStatus(422);

        $this->assertTrue((bool) $admin->fresh()->enable);

        // El cajero si se puede desactivar: no deja nada roto.
        $cashier = User::where('role', User::ROLE_CASHIER)->first();
        $this->actingAs($admin)
            ->putJson("/tickets-admin/users/{$cashier->id}", ['enable' => false])
            ->assertOk();
        $this->assertFalse((bool) $cashier->fresh()->enable);
    }

    /**
     * El admin da de alta al personal de la puerta eligiendo el rol.
     */
    public function test_se_puede_crear_un_cajero_desde_el_panel(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/tickets-admin/users', [
            'name' => 'Puerta',
            'email' => 'puerta@test.local',
            'password' => 'secreto123',
            'role' => 'cajero',
        ])->assertCreated();

        $cashier = User::where('email', 'puerta@test.local')->first();
        $this->assertTrue($cashier->is_admin);
        $this->assertTrue($cashier->isCashier());

        // Sin role sigue siendo admin, como todos los operadores de antes.
        $this->actingAs($admin)->postJson('/tickets-admin/users', [
            'name' => 'Central',
            'email' => 'central@test.local',
            'password' => 'secreto123',
        ])->assertCreated();

        $this->assertTrue(User::where('email', 'central@test.local')->first()->isStaffAdmin());

        // Y un rol que no existe se rechaza.
        $this->actingAs($admin)->postJson('/tickets-admin/users', [
            'name' => 'Inventado',
            'email' => 'inventado@test.local',
            'password' => 'secreto123',
            'role' => 'dueno',
        ])->assertStatus(422);
    }

    public function test_visitante_no_entra_a_la_api_del_panel(): void
    {
        $this->getJson('/tickets-admin/dashboard')->assertStatus(401);
        $this->getJson('/tickets-portal/api/my-orders')->assertStatus(401);
    }

    public function test_orden_ajena_no_es_visible_para_otro_comprador(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent();
        $owner = $this->buyer(['dni' => '11111111', 'email' => 'a@test.local']);
        $other = $this->buyer(['dni' => '22222222', 'email' => 'b@test.local']);

        $order = $this->actingAs($owner)->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => 1]],
        ])->json();

        $this->actingAs($other)
            ->getJson("/tickets-portal/api/orders/{$order['order_id']}/status")
            ->assertStatus(404);

        $this->actingAs($other)
            ->postJson("/tickets-portal/api/orders/{$order['order_id']}/cancel")
            ->assertStatus(404);
    }

    public function test_portal_no_publica_eventos_borrador(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/tickets-admin/events', [
            'name' => 'Evento Secreto',
            'status' => 'draft',
        ])->assertCreated();

        $events = $this->getJson('/tickets-portal/api/events')->assertOk()->json();

        $this->assertCount(0, $events);
    }

    public function test_evento_con_historia_se_cancela_en_vez_de_borrarse(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent();
        $buyer = $this->buyer();

        $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => 1]],
        ])->assertCreated();

        $response = $this->actingAs($admin)->deleteJson("/tickets-admin/events/{$eventId}");

        // 422 con el motivo: el frontend tiene que poder explicarle al admin
        // por que no se borro y que paso en su lugar.
        $response->assertStatus(422);
        $this->assertTrue($response->json('archived'));
        $this->assertSame('cancelled', DB::table('tickets_events')->where('id', $eventId)->value('status'));

        // Y no se perdio nada.
        $this->assertSame(1, DB::table('tickets_orders')->where('event_id', $eventId)->count());
        $this->assertSame(1, DB::table('tickets_tickets')->where('event_id', $eventId)->count());
    }

    public function test_publicidad_se_filtra_por_posicion_y_evento(): void
    {
        [$admin, $eventId] = $this->publishEvent();

        $this->actingAs($admin)->postJson('/tickets-admin/ads', [
            'name' => 'Banner del festival',
            'image_path' => '/uploads/tickets/ads/global.png',
            'position' => 'banner',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/tickets-admin/ads', [
            'name' => 'Sponsor del lateral',
            'image_path' => '/uploads/tickets/ads/global-side.png',
            'position' => 'sidebar',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/tickets-admin/ads', [
            'event_id' => $eventId,
            'name' => 'Patrocinio del evento',
            'image_path' => '/uploads/tickets/ads/event.png',
            'position' => 'banner',
        ])->assertCreated();

        // Sin filtro: solo los globales. Los del evento son de ese evento.
        $this->assertCount(2, $this->getJson('/tickets-portal/api/ads')->assertOk()->json());

        // Con posicion: cada zona pide la suya, no la del vecino.
        $banner = $this->getJson('/tickets-portal/api/ads?position=banner')->assertOk()->json();

        $this->assertSame(['Banner del festival'], array_column($banner, 'name'));

        // Con evento: el patrocinio del evento se suma a la publicidad global.
        $scoped = $this->getJson("/tickets-portal/api/ads?event_id={$eventId}&position=banner")
            ->assertOk()
            ->json();

        $this->assertEqualsCanonicalizing(
            ['Banner del festival', 'Patrocinio del evento'],
            array_column($scoped, 'name')
        );
    }

    public function test_configuracion_no_expone_secrets(): void
    {
        $admin = $this->admin();

        DB::table('tickets_configs')->updateOrInsert(
            ['name' => 'mp_access_token'],
            ['value' => 'APP_USR-token-super-secreto', 'type' => 'text', 'created_at' => now(), 'updated_at' => now()]
        );

        $response = $this->actingAs($admin)->getJson('/tickets-admin/config')->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString('APP_USR-token-super-secreto', $body);

        $token = collect($response->json())->firstWhere('name', 'mp_access_token');
        $this->assertSame('__set__', $token['value']);
    }

    public function test_escritura_sobre_un_secreto_se_ignora(): void
    {
        $admin = $this->admin();

        $id = DB::table('tickets_configs')->updateOrInsert(
            ['name' => 'qr_secret'],
            ['value' => 'secreto-original', 'type' => 'text', 'created_at' => now(), 'updated_at' => now()]
        ) ? DB::table('tickets_configs')->where('name', 'qr_secret')->value('id') : null;

        $this->actingAs($admin)->postJson('/tickets-admin/config', [
            'items' => [['id' => $id, 'value' => 'secreto-del-atacante']],
        ])->assertOk();

        $this->assertSame('secreto-original', DB::table('tickets_configs')->where('name', 'qr_secret')->value('value'));
    }

    /**
     * El websocket acelera, nunca decide. Con Reverb apagado no se puede
     * publicar un evento ni validar una entrada en la puerta: el broadcast
     * tira una excepcion de conexion y el 500 venia del servidor de
     * websockets, no de la operacion de negocio.
     */
    public function test_websocket_caido_no_rompe_la_publicacion(): void
    {
        $this->withBrokenBroadcaster();

        [$admin, $eventId] = $this->publishEvent();

        // El evento quedo publicado igual, y la operacion respondio bien.
        $this->assertSame('published', DB::table('tickets_events')->where('id', $eventId)->value('status'));

        $this->actingAs($admin)->postJson("/tickets-admin/events/{$eventId}/ticket-types", [
            'name' => 'General',
            'price' => 5000,
            'stock' => 10,
            'max_per_order' => 4,
            'payment_mode' => 'both',
            'max_installments' => 6,
            'enable' => true,
        ])->assertCreated();
    }

    /**
     * Reemplaza el driver de broadcast por uno que siempre tira, que es lo que
     * pasa cuando Reverb no esta levantado.
     */
    private function withBrokenBroadcaster(): void
    {
        app(\Illuminate\Contracts\Broadcasting\Factory::class)->extend('roto', function () {
            return new class implements \Illuminate\Contracts\Broadcasting\Broadcaster
            {
                public function auth($request)
                {
                }

                public function validAuthentication($request, $result)
                {
                }

                public function broadcast(array $channels, $event, array $payload = [])
                {
                    throw new \RuntimeException('Reverb caido');
                }
            };
        });

        config(['broadcasting.default' => 'roto']);
    }
}