<?php

/*
|--------------------------------------------------------------------------
| Mensajes de autenticacion en castellano
|--------------------------------------------------------------------------
|
| Los errores de login los escribe el controller a mano (son genericos a
| proposito, para no confirmar que emails estan dados de alta). Esto esta aca
| para que el resto de la app, que usa el helper auth() o el middleware, no
| tenga un unico mensaje en ingles en medio de una interfaz en castellano.
|
*/

return [

    'failed' => 'Credenciales invalidas.',
    'password' => 'La contraseña es incorrecta.',
    'throttle' => 'Demasiados intentos. Volvé a intentar en :seconds segundos.',

];