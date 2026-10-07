<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pruebas de arranque.
 *
 * La compra tiene su propio archivo (PurchaseFlowTest). Acá va lo que tiene que
 * funcionar antes de que exista un solo pedido: que la SPA cargue, que el layout
 * le pase las credenciales de Reverb al navegador y que la sesion se guarde en
 * la base de verdad.
 *
 * Estas pruebas se escribieron después de dos 500 en cadena que la suite no
 * veía: config/session.php pedia la conexion "mysql_parent", que en esta app no
 * existe, y app.blade.php leia host/port/scheme en el nivel equivocado de
 * config/reverb.php. Los tests usaban SESSION_DRIVER=array y por eso ninguno de
 * los dos se complaintaba.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_raiz_sirve_la_spa(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        // El mount de Vue y el manifest de Vite: sin esto el navegador muestra
        // la pantalla de carga eterna.
        $response->assertSee('id="app"', false);
        $response->assertSee('/build/assets/app-', false);
    }

    /**
     * El meta de Reverb es lo unico que le dice al navegador como conectarse al
     * websocket. Si falta, la app sigue funcionando con polling, pero el
     * operador no ve los cambios en vivo y nadie se da cuenta.
     */
    public function test_el_layout_pasa_las_credenciales_de_reverb_al_navegador(): void
    {
        // En los tests el broadcast va a null para no pegarle al socket; aca se
        // fuerza reverb solo para leer el layout, y esta pagina no emite nada.
        config(['broadcasting.default' => 'reverb']);

        $content = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/name="reverb-key" content="([^"]+)"/', $content, $matches));

        $meta = json_decode(base64_decode($matches[1]), true);

        $this->assertIsArray($meta);
        $this->assertNotEmpty($meta['key'], 'la key de Reverb no llego al navegador');
        $this->assertNotEmpty($meta['host'], 'el host de Reverb no llego al navegador');
        $this->assertGreaterThan(0, $meta['port'], 'el puerto de Reverb no llego al navegador');
        $this->assertContains($meta['scheme'], ['http', 'https']);
    }

    /**
     * El arranque real usa SESSION_DRIVER=database. Los tests usan array para no
     * depender de la base, y esa diferencia dejo pasar una config de sesion que
     * rompia TODAS las paginas. Esta prueba usa el driver real.
     */
    public function test_la_sesion_se_guarda_en_la_base(): void
    {
        config(['session.driver' => 'database']);

        $this->get('/')->assertOk();

        $this->assertSame(1, DB::table('sessions')->count(), 'la sesion no se persistio');

        // Y la conexion de la sesion tiene que existir de verdad. Si alguien
        // vuelve a poner un nombre de conexion que no esta en config/database.php,
        // esto falla con el mismo 500 que rompio la app.
        $connection = config('session.connection');

        $this->assertTrue(
            $connection === null || array_key_exists($connection, config('database.connections')),
            'la sesion apunta a una conexion que no existe: '.$connection
        );
    }
}