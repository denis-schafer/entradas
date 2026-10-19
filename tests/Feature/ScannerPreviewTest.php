<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Vista previa del escaner: muestra los datos del comprador antes de consumir
 * la entrada. Solo el POST /scans definitivo la marca como usada.
 */
class ScannerPreviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0:User,1:int,2:int}
     */
    private function publishEvent(string $name = 'Festival', ?User $admin = null): array
    {
        $admin ??= $this->admin(['email' => 'admin-'.uniqid().'@test.local']);

        $eventId = $this->actingAs($admin)->postJson('/tickets-admin/events', [
            'name' => $name.' '.uniqid(),
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
        ])->assertCreated()->json('id');

        return [$admin, $eventId, $typeId];
    }

    /**
     * Crea una orden pagada y devuelve la entrada con su QR.
     *
     * @return array{order_id:int,ticket_id:int,qr_payload:string}
     */
    private function paidTicket(int $eventId, int $typeId): array
    {
        $buyer = $this->buyer([
            'name' => 'Ana Perez',
            'email' => 'ana.perez@test.local',
            'dni' => '30111222',
        ]);

        $order = $this->actingAs($buyer)->postJson('/tickets-portal/api/orders', [
            'event_id' => $eventId,
            'items' => [['ticket_type_id' => $typeId, 'qty' => 1]],
        ])->assertCreated()->json();

        DB::table('tickets_orders')->where('id', $order['order_id'])->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $ticket = DB::table('tickets_tickets')->where('order_id', $order['order_id'])->first();

        return [
            'order_id' => (int) $order['order_id'],
            'ticket_id' => (int) $ticket->id,
            'qr_payload' => (string) $ticket->qr_payload,
        ];
    }

    public function test_el_preview_muestra_los_datos_del_comprador_sin_consumir_la_entrada(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent();
        $ticket = $this->paidTicket($eventId, $typeId);

        $preview = $this->actingAs($admin)->postJson('/tickets-admin/scans/preview', [
            'qr_payload' => $ticket['qr_payload'],
            'event_id' => $eventId,
        ])->assertOk();

        $this->assertSame('valid', $preview->json('result'));
        $this->assertTrue($preview->json('can_validate'));
        $this->assertSame('Ana Perez', $preview->json('ticket.buyer_name'));
        $this->assertSame('30111222', $preview->json('ticket.buyer_dni'));
        $this->assertSame('ana.perez@test.local', $preview->json('ticket.buyer_email'));
        $this->assertSame('General', $preview->json('ticket.type_name'));

        // La entrada sigue disponible y no se registro ningun escaneo.
        $this->assertSame('valid', DB::table('tickets_tickets')->where('id', $ticket['ticket_id'])->value('status'));
        $this->assertNull(DB::table('tickets_tickets')->where('id', $ticket['ticket_id'])->value('used_at'));
        $this->assertSame(0, DB::table('tickets_scans')->count());

        // Recien el POST definitivo la consume.
        $this->actingAs($admin)->postJson('/tickets-admin/scans', [
            'qr_payload' => $ticket['qr_payload'],
            'event_id' => $eventId,
        ])->assertOk()->assertJsonPath('result', 'valid');

        $this->assertSame('used', DB::table('tickets_tickets')->where('id', $ticket['ticket_id'])->value('status'));
        $this->assertSame(1, DB::table('tickets_scans')->count());
    }

    public function test_el_preview_de_una_entrada_usada_la_muestra_pero_no_permite_validar(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent();
        $ticket = $this->paidTicket($eventId, $typeId);

        DB::table('tickets_tickets')->where('id', $ticket['ticket_id'])->update([
            'status' => 'used',
            'used_at' => now(),
        ]);

        $preview = $this->actingAs($admin)->postJson('/tickets-admin/scans/preview', [
            'qr_payload' => $ticket['qr_payload'],
            'event_id' => $eventId,
        ])->assertOk();

        $this->assertSame('used', $preview->json('result'));
        $this->assertFalse($preview->json('can_validate'));
        $this->assertSame('Ana Perez', $preview->json('ticket.buyer_name'));

        // El intento queda auditado, pero no se vuelve a consumir.
        $this->assertSame('used', DB::table('tickets_tickets')->where('id', $ticket['ticket_id'])->value('status'));
        $this->assertSame(1, DB::table('tickets_scans')->where('result', 'used')->count());
    }

    public function test_el_preview_de_otro_evento_muestra_el_motivo(): void
    {
        [$admin, $eventId, $typeId] = $this->publishEvent('Evento A');
        [, $otherEventId] = $this->publishEvent('Evento B', $admin);

        $ticket = $this->paidTicket($eventId, $typeId);

        $preview = $this->actingAs($admin)->postJson('/tickets-admin/scans/preview', [
            'qr_payload' => $ticket['qr_payload'],
            'event_id' => $otherEventId,
        ])->assertOk();

        $this->assertSame('wrong_event', $preview->json('result'));
        $this->assertFalse($preview->json('can_validate'));
        $this->assertSame('Ana Perez', $preview->json('ticket.buyer_name'));

        $this->assertSame(1, DB::table('tickets_scans')->where('result', 'wrong_event')->count());
    }
}
