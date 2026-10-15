<?php

namespace Tests\Feature;

use App\Support\AppState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mantenimiento del sistema: modo test y blanqueo de la base de datos.
 *
 * Son las dos acciones mas destructivas de la app, asi que las pruebas cuidan
 * dos cosas: que el modo test solo borre lo cargado mientras estuvo activo, y
 * que el blanqueo deje exactamente el estado por defecto.
 */
class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_modo_test_solo_borra_lo_cargado_mientras_estuvo_activo(): void
    {
        $admin = $this->admin();

        // Dato real, cargado antes de activar el modo test.
        $this->actingAs($admin)->postJson('/tickets-admin/events', [
            'name' => 'Evento Real',
            'status' => 'published',
        ])->assertCreated();

        $this->actingAs($admin)
            ->postJson('/tickets-admin/app-state/test-mode', ['enabled' => true])
            ->assertOk()
            ->assertJson(['test_mode' => true]);

        // Dato de prueba, cargado con el modo test activo.
        $this->actingAs($admin)->postJson('/tickets-admin/events', [
            'name' => 'Evento de Prueba',
            'status' => 'published',
        ])->assertCreated();

        $this->assertSame(2, DB::table('tickets_events')->count());

        $this->actingAs($admin)
            ->postJson('/tickets-admin/app-state/test-mode', ['enabled' => false])
            ->assertOk()
            ->assertJson(['test_mode' => false]);

        // El real sobrevive; el de prueba desaparece.
        $this->assertSame(1, DB::table('tickets_events')->count());
        $this->assertSame('Evento Real', DB::table('tickets_events')->value('name'));
    }

    public function test_el_estado_del_modo_test_se_puede_consultar(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson('/tickets-admin/app-state')
            ->assertOk()
            ->assertJson(['test_mode' => false]);

        AppState::setTestMode(true);

        $this->actingAs($admin)
            ->getJson('/tickets-admin/app-state')
            ->assertOk()
            ->assertJson(['test_mode' => true]);
    }

    public function test_blanquear_deja_solo_al_administrador(): void
    {
        $admin = $this->admin();

        // Otro operador y un comprador, para confirmar que se borran.
        $this->cashier(['email' => 'cajero@test.local']);

        DB::table('users')->insert([
            'name' => 'Comprador',
            'dni' => '12345678',
            'password' => bcrypt('comprador123'),
            'is_admin' => false,
            'enable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/tickets-admin/events', [
            'name' => 'Evento',
            'status' => 'published',
        ])->assertCreated();

        $this->actingAs($admin)
            ->postJson('/tickets-admin/app-state/reset-database')
            ->assertOk();

        // Solo queda un usuario: Denis Schafer, admin, con su DNI.
        $this->assertSame(1, DB::table('users')->count());

        $survivor = DB::table('users')->first();

        $this->assertSame('Denis Schafer', $survivor->name);
        $this->assertSame('29923360', $survivor->dni);
        $this->assertTrue((bool) $survivor->is_admin);

        // No queda ningun dato de negocio.
        $this->assertSame(0, DB::table('tickets_events')->count());
        $this->assertSame(0, DB::table('tickets_orders')->count());

        // La configuracion y los medios de pago vuelven al default.
        $this->assertSame('Entradas', DB::table('tickets_configs')->where('name', 'business_name')->value('value'));
        $this->assertSame(2, DB::table('tickets_payment_methods')->count());
    }

    public function test_el_admin_blanqueado_puede_iniciar_sesion(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/tickets-admin/app-state/reset-database')
            ->assertOk();

        // El login por DNI normaliza a solo digitos, asi que entra igual.
        $this->postJson('/tickets-auth/login', [
            'identifier' => '29923360',
            'password' => '$0deJulio',
        ])->assertOk()->assertJson(['is_admin' => true]);
    }

    public function test_solo_el_administrador_puede_usar_mantenimiento(): void
    {
        $cashier = $this->cashier();

        $this->actingAs($cashier)
            ->getJson('/tickets-admin/app-state')
            ->assertStatus(403);

        $this->actingAs($cashier)
            ->postJson('/tickets-admin/app-state/test-mode', ['enabled' => true])
            ->assertStatus(403);

        $this->actingAs($cashier)
            ->postJson('/tickets-admin/app-state/reset-database')
            ->assertStatus(403);
    }
}
