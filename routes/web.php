<?php

use App\Http\Controllers\Portal\TicketPortalAuthController;
use App\Http\Controllers\Portal\TicketPortalEventController;
use App\Http\Controllers\Portal\TicketPortalOrderController;
use App\Http\Controllers\Portal\PortalPublicEntryController;
use App\Http\Controllers\TicketsAdController;
use App\Http\Controllers\TicketsAuthController;
use App\Http\Controllers\TicketsCheckoutController;
use App\Http\Controllers\TicketsConfigController;
use App\Http\Controllers\TicketsDashboardController;
use App\Http\Controllers\TicketsEventController;
use App\Http\Controllers\TicketsMaintenanceController;
use App\Http\Controllers\TicketsMercadoPagoController;
use App\Http\Controllers\TicketsMultipagoController;
use App\Http\Controllers\TicketsOrderController;
use App\Http\Controllers\TicketsPaymentMethodController;
use App\Http\Controllers\TicketsPosController;
use App\Http\Controllers\TicketsRaffleController;
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

// ---------------------------------------------------------------- Multipago
// Notificaciones en tiempo real, GET o POST, sin sesion y sin CSRF (ver
// bootstrap/app.php). Multipago usa UNA sola cuenta y UNA sola URL para todos
// los eventos; la key aleatoria distingue la instalacion. El evento surge de
// la orden resuelta por customer_id.
Route::prefix('tickets/multipago')->name('tickets.multipago.')->group(function () {
    Route::match(['get', 'post'], 'webhook/{key}', [TicketsMultipagoController::class, 'webhook'])
        ->name('webhook');
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

            // Usuarios del panel y compradores. El listado de usuarios y su ABM
            // viven en el grupo de gestion de mas abajo (tickets.manage), porque
            // el administrativo tambien da de alta vendedores. El listado de
            // compradores queda aca: solo lo ve el administrador.
            Route::get('users/buyers', [TicketsUserController::class, 'buyers'])->name('users.buyers');

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

            /*
            | Mantenimiento. Solo admin (lo revalida el controlador): modo test
            | y blanqueo total de la base.
            */
            Route::get('app-state', [TicketsMaintenanceController::class, 'state'])->name('app-state');
            Route::post('app-state/test-mode', [TicketsMaintenanceController::class, 'setTestMode'])->name('app-state.test-mode');
            Route::post('app-state/reset-database', [TicketsMaintenanceController::class, 'resetDatabase'])->name('app-state.reset-database');

            // Medios de pago
            Route::get('payment-methods', [TicketsPaymentMethodController::class, 'index'])->name('payment-methods.index');
            Route::put('payment-methods/{code}', [TicketsPaymentMethodController::class, 'update'])->name('payment-methods.update');
            Route::put('payment-methods/{code}/events/{eventId}', [TicketsPaymentMethodController::class, 'updateEvent'])->whereNumber('eventId')->name('payment-methods.update-event');
            Route::post('payment-methods/{code}/test', [TicketsPaymentMethodController::class, 'test'])->name('payment-methods.test');
            Route::post('payment-methods/{code}/validate', [TicketsPaymentMethodController::class, 'validatePayments'])->name('payment-methods.validate');
            Route::get('payment-methods/{code}/webhook-url', [TicketsPaymentMethodController::class, 'webhookUrl'])->name('payment-methods.webhook-url');
            Route::post('payment-methods/{code}/webhook-key/regenerate', [TicketsPaymentMethodController::class, 'regenerateWebhookKey'])->name('payment-methods.webhook-key');
        });

        // ------------------------------------------------------------- Gestion
        // Secciones que comparte el administrativo con el admin: estadisticas
        // (acotadas a sus eventos) y alta/edicion de vendedores.
        Route::middleware('tickets.manage')->group(function () {
            Route::get('statistics', [TicketsStatisticsController::class, 'summary'])->name('statistics');
            Route::get('statistics/export', [TicketsStatisticsController::class, 'export'])->name('statistics.export');

            Route::get('users', [TicketsUserController::class, 'index'])->name('users.index');
            Route::post('users', [TicketsUserController::class, 'store'])->name('users.store');
            Route::put('users/{id}', [TicketsUserController::class, 'update'])->whereNumber('id')->name('users.update');
            Route::delete('users/{id}', [TicketsUserController::class, 'destroy'])->whereNumber('id')->name('users.destroy');
            Route::post('users/{id}/reset-password', [TicketsUserController::class, 'resetPassword'])->whereNumber('id')->name('users.reset');

            /*
            | Medios de pago asignables a un operador (vendedor/administrativo)
            | segun los eventos elegidos. Vive aca y no en el grupo de staff
            | porque el administrativo tambien da de alta vendedores.
            */
            Route::get('payment-methods/available', [TicketsPaymentMethodController::class, 'available'])->name('payment-methods.available');

            // Sorteos. Admin y administrativo (acotado a sus eventos).
            Route::get('raffles', [TicketsRaffleController::class, 'index'])->name('raffles.index');
            Route::post('raffles', [TicketsRaffleController::class, 'store'])->name('raffles.store');
            Route::get('raffles/{id}', [TicketsRaffleController::class, 'show'])->whereNumber('id')->name('raffles.show');
            Route::put('raffles/{id}', [TicketsRaffleController::class, 'update'])->whereNumber('id')->name('raffles.update');
            Route::delete('raffles/{id}', [TicketsRaffleController::class, 'destroy'])->whereNumber('id')->name('raffles.destroy');
            Route::get('raffles/{id}/eligible', [TicketsRaffleController::class, 'eligible'])->whereNumber('id')->name('raffles.eligible');
            Route::post('raffles/{id}/prizes/{prizeId}/draw', [TicketsRaffleController::class, 'draw'])
                ->whereNumber(['id', 'prizeId'])->name('raffles.draw');
            Route::post('raffles/{id}/prizes/{prizeId}/reset', [TicketsRaffleController::class, 'reset'])
                ->whereNumber(['id', 'prizeId'])->name('raffles.reset');
        });

        // ------------------------------------------------------------- POS
        // Venta en la caja: efectivo o link de pago. Admin, administrativo y
        // vendedor.
        Route::middleware('tickets.pos')->group(function () {
            Route::get('pos/events', [TicketsPosController::class, 'events'])->name('pos.events');
            Route::get('pos/events/{eventId}/ticket-types', [TicketsPosController::class, 'ticketTypes'])->whereNumber('eventId')->name('pos.ticket-types');
            Route::get('pos/events/{eventId}/payment-methods', [TicketsPosController::class, 'paymentMethods'])->whereNumber('eventId')->name('pos.payment-methods');
            Route::get('pos/buyers', [TicketsPosController::class, 'buyers'])->name('pos.buyers');
            Route::post('pos/buyers', [TicketsPosController::class, 'storeBuyer'])->name('pos.buyers.store');
            Route::get('pos/sales', [TicketsPosController::class, 'sales'])->name('pos.sales.index');
            Route::post('pos/sales', [TicketsPosController::class, 'sell'])->name('pos.sales');
            Route::get('pos/orders/{id}/receipt', [TicketsPosController::class, 'receipt'])->whereNumber('id')->name('pos.receipt');
            Route::get('pos/orders/{id}/payment-link', [TicketsPosController::class, 'paymentLink'])->whereNumber('id')->name('pos.payment-link');
            Route::get('pos/orders/{id}/multipago-qr.svg', [TicketsPosController::class, 'multipagoQrSvg'])->whereNumber('id')->name('pos.multipago-qr');
            Route::get('pos/tickets/{id}/qr.svg', [TicketsPosController::class, 'ticketQr'])->whereNumber('id')->name('pos.ticket-qr');
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

        // Checkout con medio elegido
        Route::get('orders/{orderId}/payment-methods', [TicketsCheckoutController::class, 'methods'])->whereNumber('orderId')->name('orders.payment-methods');
        Route::get('orders/{orderId}/multipago-code', [TicketsCheckoutController::class, 'multipagoCode'])->whereNumber('orderId')->name('orders.multipago-code');
        Route::get('orders/{orderId}/multipago-qr.svg', [TicketsCheckoutController::class, 'multipagoQrSvg'])->whereNumber('orderId')->name('orders.multipago-qr');
    });
});

/*
| Venta publica: el link que el vendedor le manda al comprador por WhatsApp.
| La credencial del path es el public_token de la orden, un UUID aleatorio, y
| la vista solo muestra esa venta. No piden sesion ni CSRF (son GET).
*/
Route::prefix('tickets-portal/api/public/entry')->name('tickets-portal.api.public-entry.')->group(function () {
    Route::get('{publicToken}', [PortalPublicEntryController::class, 'show'])->where('publicToken', '[0-9a-fA-F\-]{36}')->name('show');
    Route::get('{publicToken}/{ticketId}/qr.svg', [PortalPublicEntryController::class, 'ticketQr'])->whereNumber('ticketId')->where('publicToken', '[0-9a-fA-F\-]{36}')->name('ticket-qr');
});

/*
| Estado publico de un sorteo para la ventana de proyeccion. La credencial del
| path es el public_token del sorteo (UUID aleatorio) y no pide sesion ni CSRF.
*/
Route::get('tickets-portal/api/public/raffle/{publicToken}', [TicketsRaffleController::class, 'publicState'])
    ->where('publicToken', '[0-9a-fA-F\-]{36}')
    ->name('tickets-portal.api.public-raffle');

/*
| El archivo SPA se sirve tambien sobre /e/{token}, que es el path del link.
| El frontend lee el pathname, saca el token y monta la vista publica de la
| venta. Los assets y el cliente HTTP usan rutas absolutas, asi que servir la
| app sobre este path no rompe nada.
*/
Route::get('/e/{publicToken}', fn () => view('app'))->where('publicToken', '[0-9a-fA-F\-]{36}');

/*
| El proyector de un sorteo se sirve igual: /sorteo/{token} monta la SPA, que
| lee el pathname y muestra la ventana publica de resultados. Misma idea que
| /e/{token}: assets absolutos, sin romper nada.
*/
Route::get('/sorteo/{publicToken}', fn () => view('app'))->where('publicToken', '[0-9a-fA-F\-]{36}');

/*
| Catch-all defensivo. Cualquier ruta desconocida (un bookmark viejo a
| /tickets-portal/my-tickets, por ejemplo) devuelve el index con un 200 en vez
| de un 404: el frontend decide que pantalla montar. Las de la API y los
| archivos estaticos ya quedaron atendidos antes, porque public/ y las rutas
| de arriba tienen prioridad.
*/
Route::fallback(fn () => redirect('/'));