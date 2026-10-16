<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orden manual de los eventos.
 *
 * El panel reordena arrastrando y soltando (POST events/reorder) y ese mismo
 * orden es el que ve el portal en "Proximos eventos".
 */
class EventsOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(User $admin, string $name, string $status = 'published'): int
    {
        return $this->actingAs($admin)->postJson('/tickets-admin/events', [
            'name' => $name,
            'status' => $status,
        ])->assertCreated()->json('id');
    }

    /**
     * @return list<int>
     */
    private function panelOrder(User $admin): array
    {
        return array_column(
            $this->actingAs($admin)->getJson('/tickets-admin/events')->assertOk()->json('data'),
            'id'
        );
    }

    public function test_los_eventos_nuevos_se_agregan_al_final_del_orden(): void
    {
        $admin = $this->admin();

        $a = $this->makeEvent($admin, 'Alfa');
        $b = $this->makeEvent($admin, 'Beta');
        $c = $this->makeEvent($admin, 'Gama');

        $this->assertSame([$a, $b, $c], $this->panelOrder($admin));

        // Un evento nuevo cae despues del ultimo, no arriba.
        $d = $this->makeEvent($admin, 'Delta');
        $this->assertSame([$a, $b, $c, $d], $this->panelOrder($admin));
    }

    public function test_reordenar_permuta_el_orden_y_el_portal_lo_respeta(): void
    {
        $admin = $this->admin();

        $a = $this->makeEvent($admin, 'Alfa');
        $b = $this->makeEvent($admin, 'Beta');
        $c = $this->makeEvent($admin, 'Gama');

        $this->actingAs($admin)->postJson('/tickets-admin/events/reorder', [
            'ids' => [$c, $a, $b],
        ])->assertOk();

        // El panel refleja el nuevo orden...
        $this->assertSame([$c, $a, $b], $this->panelOrder($admin));

        // ...y el portal usa ese mismo orden, no la fecha del evento.
        $portal = $this->getJson('/tickets-portal/api/events')->assertOk()->json();
        $this->assertSame([$c, $a, $b], array_column($portal, 'id'));
    }

    public function test_reordenar_con_un_evento_inexistente_devuelve_422(): void
    {
        $admin = $this->admin();
        $a = $this->makeEvent($admin, 'Alfa');

        $this->actingAs($admin)->postJson('/tickets-admin/events/reorder', [
            'ids' => [$a, 999999],
        ])->assertStatus(422);
    }
}
