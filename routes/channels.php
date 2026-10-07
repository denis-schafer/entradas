<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Tres canales:
|
|   tickets.events           publico   -> stock y estado de los eventos.
|                                         Lo consumen home, ficha y detalle.
|                                         No lleva datos de comprador: solo
|                                         disponibilidad y estado.
|
|   tickets.order.{token}    privado   -> estado de una orden concreta. El
|                                         token es el public_token (uuid), no
|                                         el id, para que no sea adivinable.
|                                         Es privado, no publico: el token
|                                         viaja en la URL de retorno de
|                                         MercadoPago, asi que puede quedar en
|                                         historial o en logs. Ser privado y
|                                         verificar que la orden sea del usuario
|                                         conectado evita que un token filtrado
|                                         sirva para espiar el estado de un
|                                         pago ajeno.
|
|   tickets.admin            privado   -> ordenes y escaneos del panel.
|
*/

Broadcast::channel('tickets.admin', function (User $user) {
    return (bool) $user->is_admin && (bool) $user->enable;
});

Broadcast::channel('tickets.order.{token}', function (User $user, string $token) {
    if ((bool) $user->is_admin) {
        return true;
    }

    return DB::table('tickets_orders')
        ->where('buyer_user_id', $user->id)
        ->where('public_token', $token)
        ->exists();
});