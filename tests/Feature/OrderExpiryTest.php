<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OrderExpiry;
use App\Services\OrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vigencia de las ordenes pendientes.
 *
 * La regla de negocio es: una reserva vale hasta el final del dia en que se
 * creo (23:59:59) o hasta que cierre la venta del tipo, lo que ocurra primero.
 * Si no se paga, se vence y se libera el stock. Ademas, el precio no puede
 * quedar viejo y un pago aprobado antes del deadline se acepta aunque el aviso
 * llegue mas tarde.
 */
class OrderExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array{0:User,1:int,2:int}
     */
    private function publishEvent(array $typeOverrides = []): array
    {
        $admin = $this->admin();

        $eventId = $this->actingAs($admin)->postJson('/tickets-admin/events', [
            'name' => 'Festival '.uniqid(),
            'starts_at' => now()->addMonth()->toDateTimeString(),
            'status' => 'published',
        ])->assertCreated()->json('id');

        $typeId = $this->actingAs($admin)->postJson("/tickets-admin/events/{$eventId}/ticket-types", [
            'name' => 'General',
            'price' => 5000,
            'stock' => 10,
            'max_per_order' => 4,
            'payment_mode' => 'both',
            'max_installments' => 6,
            'enable' => true,
            ...$typeOverrides,
        ])->assertCreated()->json('id');

        return [$admin, $eventId, $typeId];
    }

    /**
     * @return array<string,mixed>
     */
    private function buy(User $buyer, int $eventId, int $typeId, int $qty = 1): array
    {
        return $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => $qty]],
        ])->assertCreated()->json();
    }

    private function fakeMercadoPagoToken(int $eventId): void
    {
        $mp = DB::table('tickets_payment_methods')->where('code', 'mercadopago')->first();

        DB::table('tickets_payment_methods')->where('id', $mp->id)->update(['enabled' => 1]);

        DB::table('tickets_event_payment_method')->updateOrInsert(
            ['event_id' => $eventId, 'payment_method_id' => $mp->id],
            [
                'enabled' => 1,
                'config' => json_encode(['access_token' => 'APP_USR-token-de-prueba']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function test_una_orden_pendiente_vale_hasta_el_final_del_dia(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');

        [, $eventId, $typeId] = $this->publishEvent();
        $buyer = $this->buyer();
        $order = $this->buy($buyer, $eventId, $typeId);

        $this->actingAs($buyer)
            ->getJson("/tickets-portal/api/orders/{$order['order_id']}/status")
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $this->assertSame(1, (int) DB::table('tickets_event_ticket_types')->where('id', $typeId)->value('sold_count'));

        // Un minuto antes de medianoche todavia se puede pagar.
        Carbon::setTestNow('2026-10-19 23:59:00');

        $this->actingAs($buyer)
            ->getJson("/tickets-portal/api/orders/{$order['order_id']}/status")
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        // Pasada la medianoche, la reserva se vence y el stock vuelve.
        Carbon::setTestNow('2026-10-20 00:00:01');

        $this->actingAs($buyer)
            ->getJson("/tickets-portal/api/orders/{$order['order_id']}/status")
            ->assertOk()
            ->assertJsonPath('status', 'expired');

        $this->assertSame('expired', DB::table('tickets_orders')->where('id', $order['order_id'])->value('status'));
        $this->assertSame('expired', DB::table('tickets_tickets')->where('order_id', $order['order_id'])->value('status'));
        $this->assertSame(0, (int) DB::table('tickets_event_ticket_types')->where('id', $typeId)->value('sold_count'));
    }

    public function test_no_se_genera_el_pago_de_una_orden_de_otro_dia(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');

        [, $eventId, $typeId] = $this->publishEvent();
        $buyer = $this->buyer();
        $order = $this->buy($buyer, $eventId, $typeId);

        Carbon::setTestNow('2026-10-20 00:01:00');

        $this->actingAs($buyer)
            ->getJson("/tickets-portal/api/orders/{$order['order_id']}/payment-methods")
            ->assertStatus(410);

        $this->assertSame('expired', DB::table('tickets_orders')->where('id', $order['order_id'])->value('status'));
    }

    public function test_la_reserva_tambien_vence_al_cerrar_la_venta_del_tipo(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');

        [, $eventId, $typeId] = $this->publishEvent(['sale_end_at' => '2026-10-19 18:00:00']);
        $buyer = $this->buyer();
        $order = $this->buy($buyer, $eventId, $typeId);

        Carbon::setTestNow('2026-10-19 17:59:00');

        $this->actingAs($buyer)
            ->getJson("/tickets-portal/api/orders/{$order['order_id']}/status")
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        Carbon::setTestNow('2026-10-19 18:00:01');

        $this->actingAs($buyer)
            ->getJson("/tickets-portal/api/orders/{$order['order_id']}/status")
            ->assertOk()
            ->assertJsonPath('status', 'expired');
    }

    public function test_no_se_crea_una_orden_fuera_de_la_ventana_de_venta(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');

        [, $eventId, $typeId] = $this->publishEvent(['sale_end_at' => '2026-10-19 18:00:00']);
        $buyer = $this->buyer();

        Carbon::setTestNow('2026-10-19 18:00:01');

        $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => 1]],
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('tickets_orders')->count());
    }

    public function test_el_cambio_de_precio_invalida_la_orden_pendiente(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');

        [$admin, $eventId, $typeId] = $this->publishEvent();
        $buyer = $this->buyer();
        $order = $this->buy($buyer, $eventId, $typeId);

        $this->actingAs($admin)->putJson("/tickets-admin/events/{$eventId}/ticket-types/{$typeId}", [
            'price' => 6000,
        ])->assertOk();

        $this->actingAs($buyer)
            ->getJson("/tickets-portal/api/orders/{$order['order_id']}/payment-methods")
            ->assertStatus(409);

        $this->assertSame('expired', DB::table('tickets_orders')->where('id', $order['order_id'])->value('status'));
        $this->assertSame('expired', DB::table('tickets_tickets')->where('order_id', $order['order_id'])->value('status'));
    }

    public function test_un_pago_aprobado_antes_del_deadline_se_acepta_aunque_el_aviso_llegue_despues(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');

        [, $eventId, $typeId] = $this->publishEvent();
        $buyer = $this->buyer();
        $order = $this->buy($buyer, $eventId, $typeId);

        // El cron ya la vencio a las 00:10, pero el proveedor la habia
        // aprobado a las 23:59.
        Carbon::setTestNow('2026-10-20 00:10:00');
        $pending = DB::table('tickets_orders')->where('id', $order['order_id'])->first();
        app(OrderExpiry::class)->expireIfPastDue($pending);

        $expired = DB::table('tickets_orders')->where('id', $order['order_id'])->first();

        $result = app(OrderPaymentService::class)->markPaid(
            $expired,
            'pago-aprobado-antes',
            ['transaction_amount' => 5000],
            'mercadopago',
            '2026-10-19 23:59:00',
        );

        $this->assertTrue($result['changed']);
        $this->assertSame('paid', DB::table('tickets_orders')->where('id', $order['order_id'])->value('status'));
        $this->assertSame('valid', DB::table('tickets_tickets')->where('order_id', $order['order_id'])->value('status'));
        $this->assertSame(1, (int) DB::table('tickets_event_ticket_types')->where('id', $typeId)->value('sold_count'));
    }

    public function test_un_pago_aprobado_despues_del_deadline_no_se_acredita(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');

        [, $eventId, $typeId] = $this->publishEvent();
        $buyer = $this->buyer();
        $order = $this->buy($buyer, $eventId, $typeId);

        Carbon::setTestNow('2026-10-20 00:10:00');
        $pending = DB::table('tickets_orders')->where('id', $order['order_id'])->first();
        app(OrderExpiry::class)->expireIfPastDue($pending);

        $expired = DB::table('tickets_orders')->where('id', $order['order_id'])->first();

        $result = app(OrderPaymentService::class)->markPaid(
            $expired,
            'pago-aprobado-despues',
            ['transaction_amount' => 5000],
            'mercadopago',
            '2026-10-20 00:05:00',
        );

        $this->assertFalse($result['changed']);
        $this->assertSame('outside_window', $result['reason']);
        $this->assertSame('expired', DB::table('tickets_orders')->where('id', $order['order_id'])->value('status'));
    }

    public function test_el_webhook_de_mercadopago_no_acredita_un_importe_distinto(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');

        [, $eventId, $typeId] = $this->publishEvent();
        $this->fakeMercadoPagoToken($eventId);

        $buyer = $this->buyer();
        $order = $this->buy($buyer, $eventId, $typeId);

        Http::fake([
            'api.mercadopago.com/v1/payments/*' => Http::response([
                'status' => 'approved',
                'external_reference' => 'ENTRADAS-TICKET-'.$order['order_id'],
                'transaction_amount' => 4000,
                'date_approved' => '2026-10-19T12:00:00-03:00',
            ]),
        ]);

        $this->postJson("/tickets/mp/webhook/{$eventId}", [
            'type' => 'payment',
            'data' => ['id' => '123456'],
        ])->assertOk();

        $this->assertSame('pending', DB::table('tickets_orders')->where('id', $order['order_id'])->value('status'));
    }

    public function test_la_preferencia_de_mercadopago_vence_al_final_del_dia(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');

        [, $eventId, $typeId] = $this->publishEvent();
        $this->fakeMercadoPagoToken($eventId);
        config(['services.mercadopago.require_public_url' => false]);

        $buyer = $this->buyer();
        $order = $this->buy($buyer, $eventId, $typeId);

        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'pref-expira-hoy',
                'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-expira-hoy',
                'sandbox_init_point' => 'https://sandbox.mercadopago.com.ar/checkout/v1/redirect?pref_id=pref-expira-hoy',
            ]),
        ]);

        $this->actingAs($buyer)
            ->postJson("/tickets-portal/api/orders/{$order['order_id']}/preference")
            ->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'checkout/preferences')) {
                return false;
            }

            $data = $request->data();

            return ($data['expires'] ?? false) === true
                && str_contains((string) ($data['expiration_date_to'] ?? ''), '2026-10-19T23:59:59');
        });
    }

    public function test_el_comando_vence_las_ordenes_pendientes_y_libera_el_stock(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');

        [, $eventId, $typeId] = $this->publishEvent();
        $buyer = $this->buyer();
        $order = $this->buy($buyer, $eventId, $typeId);

        Carbon::setTestNow('2026-10-20 00:20:00');

        $this->artisan('tickets:expire-orders')->assertSuccessful();

        $this->assertSame('expired', DB::table('tickets_orders')->where('id', $order['order_id'])->value('status'));
        $this->assertSame(0, (int) DB::table('tickets_event_ticket_types')->where('id', $typeId)->value('sold_count'));
    }
}
