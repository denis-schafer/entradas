<?php

use App\Http\Controllers\Portal\TicketPortalAuthController;
use App\Http\Controllers\Portal\TicketPortalEventController;
use App\Http\Controllers\Portal\TicketPortalOrderController;
use App\Http\Controllers\TicketsAdController;
use App\Http\Controllers\TicketsAuthController;
use App\Http\Controllers\TicketsConfigController;
use App\Http\Controllers\TicketsDashboardController;
use App\Http\Controllers\TicketsEventController;
use App\Http\Controllers\TicketsMercadoPagoController;
use App\Http\Controllers\TicketsOrderController;
use App\Http\Controllers\TicketsScanController;
use App\Http\Controllers\TicketsStatisticsController;
use App\Http\Controllers\TicketsTicketController;
use App\Http\Controllers\TicketsUserController;
use App\Http\Controllers\TicketTypeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas
|--------------------------------------------------------------------------
| La app se sirve SIEMPRE en http://entradas.test: no hay vue-router ni rutas
| en el lado del cliente, la navegacion es por estado y montaje/desmontaje de
| componentes. Por eso el unico lugar donde se sirve el index.html es "/", y
| el catch-all final existe solo para que un bookmark viejo o una recarga en
| una subruta no devuelva un 404: redirige a la raiz.
|
| Estas rutas de API existen en el servidor, pero el navegador nunca las
| escribe en la barra de direcciones: van por fetch.
*/

// ---------------------------------------------------------------- SPA
Route::get('/', fn () => view('app'));

// ---------------------------------------------------------------- MercadoPago
// Fuera del grupo web autenticado: MercadoPago no tiene sesion ni token.
Route::prefix('tickets/mp')->name('tickets.mp.')->group(function () {
    /*
    | Webhook por evento: cada evento tiene su propio access_token y MP nos manda
    | el pago a esta URL con el id del evento en la ruta. Asi el handler ya sabe
    | que token usar antes de poder llamar a la API. Si el evento no tiene token,
    | MP rechazaria el webhook antes de mandarlo: mejor.
    */
    Route::post('webhook/{event_id}', [TicketsMercadoPagoController::class, 'webhook'])->name('webhook');
    Route::get('callback', [TicketsMercadoPagoController::class, 'callback'])->name('callback');
});

// ---------------------------------------------------------------- Panel
// Login unico. Fuera de /tickets-admin porque no es del panel: es el acceso a
// la app entera y acepta email (admin) o DNI (comprador) en el mismo campo.
// Decide el shell del lado del cliente con is_admin.
Route::post('tickets-auth/login', [TicketsAuthController::class, 'loginUnified'])
    ->name('tickets-auth.login');

// ---------------------------------------------------------------- Sesion
// Renueva la sesion desde el aviso de "por vencer" (ver sessionWatch.js).
// Devuelve 401 si ya no hay usuario, para que el frontend caiga al login en vez
// de fingir que la sesion sigue viva.
Route::post('session/refresh', [TicketsAuthController::class, 'refreshSession'])
    ->name('session.refresh');

Route::prefix('tickets-admin')->name('tickets-admin.')->group(function () {
    // Sesion. Fuera del grupo protegido a proposito: el login tiene que
    // funcionar justamente cuando no hay sesion, y /me tiene que poder
    // responder "no autenticado" en vez de 401 para que la SPA decida que
    // pantalla montar.
    Route::post('auth/login', [TicketsAuthController::class, 'login'])->name('auth.login');
    Route::get('auth/me', [TicketsAuthController::class, 'me'])->name('auth.me');

    Route::middleware('tickets.auth')->group(function () {
        Route::post('auth/logout', [TicketsAuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/change-password', [TicketsAuthController::class, 'changePassword'])->name('auth.change-password');

        // ------------------------------------------------------------- Puerta
        // Lo unico que ve un cajero. Sin tickets.staff: tickets.auth ya verifico
        // que esta autenticado y habilitado, y acao no se separa por rol.
        Route::get('scans', [TicketsScanController::class, 'index'])->name('scans.index');
        Route::post('scans', [TicketsScanController::class, 'scan'])->name('scans.scan');

        // El cajero necesita leer los eventos para elegir contra cual esta
        // escaneando. Solo lectura: el listado, nada de crear ni editar. Por eso
        // esta ruta vive aca y no en el grupo de administracion, donde el mismo
        // GET events quedaria con las demas.
        Route::get('events', [TicketsEventController::class, 'index'])->name('events.index');

        // ------------------------------------------------------------- Panel
        // Todo lo de administrar. tickets.staff niega el paso a un cajero, asi
        // que cada endpoint nuevo que se cuelgue aca queda cerrado por default.
        Route::middleware('tickets.staff')->group(function () {
            Route::get('dashboard', [TicketsDashboardController::class, 'index'])->name('dashboard');
            Route::get('statistics', [TicketsStatisticsController::class, 'summary'])->name('statistics');
            Route::get('statistics/export', [TicketsStatisticsController::class, 'export'])->name('statistics.export');

            // Eventos
            Route::post('events', [TicketsEventController::class, 'store'])->name('events.store');
            // upload va antes de {id} para que "upload" no se lea como un id.
            Route::post('events/upload', [TicketsEventController::class, 'upload'])->name('events.upload');
            Route::get('events/{id}', [TicketsEventController::class, 'show'])->whereNumber('id')->name('events.show');
            Route::put('events/{id}', [TicketsEventController::class, 'update'])->whereNumber('id')->name('events.update');
            Route::delete('events/{id}', [TicketsEventController::class, 'destroy'])->whereNumber('id')->name('events.destroy');

            // Tipos de entrada
            Route::post('ticket-types/upload', [TicketTypeController::class, 'upload'])->name('ticket-types.upload');
            Route::get('events/{eventId}/ticket-types', [TicketTypeController::class, 'index'])->whereNumber('eventId')->name('ticket-types.index');
            Route::post('events/{eventId}/ticket-types', [TicketTypeController::class, 'store'])->whereNumber('eventId')->name('ticket-types.store');
            Route::put('events/{eventId}/ticket-types/{id}', [TicketTypeController::class, 'update'])->whereNumber(['eventId', 'id'])->name('ticket-types.update');
            Route::delete('events/{eventId}/ticket-types/{id}', [TicketTypeController::class, 'destroy'])->whereNumber(['eventId', 'id'])->name('ticket-types.destroy');

            // Ordenes
            Route::get('orders', [TicketsOrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{id}', [TicketsOrderController::class, 'show'])->whereNumber('id')->name('orders.show');
            Route::post('orders/{id}/reconcile', [TicketsOrderController::class, 'reconcile'])->whereNumber('id')->name('orders.reconcile');

            // Entradas
            Route::get('tickets', [TicketsTicketController::class, 'index'])->name('tickets.index');
            Route::get('tickets/{id}', [TicketsTicketController::class, 'show'])->whereNumber('id')->name('tickets.show');
            Route::post('tickets/{id}/wristband', [TicketsTicketController::class, 'giveWristband'])->whereNumber('id')->name('tickets.wristband');
            Route::post('tickets/{id}/cancel', [TicketsTicketController::class, 'cancel'])->whereNumber('id')->name('tickets.cancel');
            Route::get('tickets/{id}/qr.svg', [TicketsMercadoPagoController::class, 'qrSvg'])->whereNumber('id')->name('tickets.qr');

            // Usuarios del panel y compradores
            Route::get('users', [TicketsUserController::class, 'index'])->name('users.index');
            Route::post('users', [TicketsUserController::class, 'store'])->name('users.store');
            Route::get('users/buyers', [TicketsUserController::class, 'buyers'])->name('users.buyers');
            Route::put('users/{id}', [TicketsUserController::class, 'update'])->whereNumber('id')->name('users.update');
            Route::delete('users/{id}', [TicketsUserController::class, 'destroy'])->whereNumber('id')->name('users.destroy');
            Route::post('users/{id}/reset-password', [TicketsUserController::class, 'resetPassword'])->whereNumber('id')->name('users.reset');

            // Publicidad
            Route::get('ads', [TicketsAdController::class, 'index'])->name('ads.index');
            Route::post('ads', [TicketsAdController::class, 'store'])->name('ads.store');
            Route::post('ads/upload', [TicketsAdController::class, 'upload'])->name('ads.upload');
            Route::put('ads/{id}', [TicketsAdController::class, 'update'])->whereNumber('id')->name('ads.update');
            Route::delete('ads/{id}', [TicketsAdController::class, 'destroy'])->whereNumber('id')->name('ads.destroy');

            // Configuracion
            Route::get('config', [TicketsConfigController::class, 'index'])->name('config.index');
            Route::post('config', [TicketsConfigController::class, 'update'])->name('config.update');
            Route::post('config/upload', [TicketsConfigController::class, 'upload'])->name('config.upload');
            Route::delete('config/image', [TicketsConfigController::class, 'deleteImage'])->name('config.delete-image');
            Route::get('config/mp-authorize-url', [TicketsConfigController::class, 'getMpOAuthUrl'])->name('config.mp-authorize');
            Route::get('config/mp-callback', [TicketsMercadoPagoController::class, 'oauthCallback'])->name('config.mp-callback');
            Route::get('config/mp-status', [TicketsConfigController::class, 'mpStatus'])->name('config.mp-status');
            /*
            | Ping a un endpoint publico de MP con el access_token guardado.
            | Sirve para distinguir "el token es valido" de "el token esta mal"
            | sin tener que cerrar un pago entero.
            */
            Route::post('config/mp-test', [TicketsConfigController::class, 'testMpToken'])->name('config.mp-test');
        });
    });
});

// ---------------------------------------------------------------- Portal
Route::prefix('tickets-portal/api')->name('tickets-portal.api.')->group(function () {
    // Publico: contenido del sitio. Sin sesion.
    Route::get('events', [TicketPortalEventController::class, 'index'])->name('events');
    Route::get('events/{slug}', [TicketPortalEventController::class, 'show'])->name('events.show');
    Route::get('ads', [TicketPortalEventController::class, 'ads'])->name('ads');
    Route::get('config', [TicketPortalEventController::class, 'config'])->name('config');

    // Sesion del portal
    Route::post('auth/register', [TicketPortalAuthController::class, 'register'])->name('auth.register');
    Route::post('auth/login', [TicketPortalAuthController::class, 'login'])->name('auth.login');
    Route::get('auth/me', [TicketPortalAuthController::class, 'me'])->name('auth.me');
    Route::post('auth/logout', [TicketPortalAuthController::class, 'logout'])->name('auth.logout');

    // Todo lo de abajo necesita comprador autenticado.
    Route::middleware('tickets.portal')->group(function () {
        Route::post('auth/change-password', [TicketPortalAuthController::class, 'changePassword'])->name('auth.change-password');
        Route::put('auth/profile', [TicketPortalAuthController::class, 'updateProfile'])->name('auth.profile');

        Route::get('my-tickets', [TicketPortalOrderController::class, 'myTickets'])->name('my-tickets');
        Route::get('my-tickets/{id}/qr.svg', [TicketPortalOrderController::class, 'myTicketQr'])->whereNumber('id')->name('my-tickets.qr');
        Route::get('my-orders', [TicketPortalOrderController::class, 'myOrders'])->name('my-orders');
        Route::get('orders/{orderId}/status', [TicketPortalOrderController::class, 'paymentStatus'])->whereNumber('orderId')->name('orders.status');
        Route::get('orders/{orderId}/token', [TicketsMercadoPagoController::class, 'orderToken'])->whereNumber('orderId')->name('orders.token');
        Route::post('orders/{orderId}/cancel', [TicketPortalOrderController::class, 'cancel'])->whereNumber('orderId')->name('orders.cancel');

        Route::post('orders', [TicketPortalOrderController::class, 'create'])->name('orders.create');
        Route::post('orders/{orderId}/preference', [TicketsMercadoPagoController::class, 'createPreference'])->whereNumber('orderId')->name('orders.preference');
    });
});

/*
| Catch-all defensivo. Cualquier ruta desconocida (un bookmark viejo a
| /tickets-portal/my-tickets, por ejemplo) devuelve el index con un 200 en vez
| de un 404: el frontend decide que pantalla montar. Las de la API y los
| archivos estaticos ya quedaron atendidos antes, porque public/ y las rutas
| de arriba tienen prioridad.
*/
Route::fallback(fn () => redirect('/'));