<?php

/*
|--------------------------------------------------------------------------
| Mensajes de validacion en-castellano
|--------------------------------------------------------------------------
|
| La app esta en castellano y .env tiene APP_LOCALE=es. Sin este archivo Laravel
| no encuentra traduccion y cae en english: los errores de formulario salian
| como "The password field must be at least 6 characters." al lado de una app
| escrita toda en castellano.
|
| El placeholder :attribute se reemplaza por el nombre del campo. El backend
| manda los nombres en ingles (identifier, password, event_id...) porque los
| codigos de la base y los tests no cambian de idioma; el "custom" de mas abajo
| es el que traduce esos nombres a algo que el usuario entienda.
|
*/

return [

    'accepted' => 'El campo :attribute debe ser aceptado.',
    'accepted_if' => 'El campo :attribute debe ser aceptado cuando :other es :value.',
    'active_url' => 'El campo :attribute no es una URL válida.',
    'after' => 'El campo :attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => 'El campo :attribute debe ser una fecha posterior o igual a :date.',
    'alpha' => 'El campo :attribute solo puede contener letras.',
    'alpha_dash' => 'El campo :attribute solo puede contener letras, números, guiones y guiones bajos.',
    'alpha_num' => 'El campo :attribute solo puede contener letras y números.',
    'array' => 'El campo :attribute debe ser una lista.',
    'ascii' => 'El campo :attribute solo puede contener caracteres y símbolos de un byte.',
    'before' => 'El campo :attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => 'El campo :attribute debe ser una fecha anterior o igual a :date.',
    'between' => [
        'array' => 'El campo :attribute debe tener entre :min y :max elementos.',
        'file' => 'El campo :attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => 'El campo :attribute debe estar entre :min y :max.',
        'string' => 'El campo :attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'can' => 'El campo :attribute contiene un valor no permitido.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'contains' => 'Al campo :attribute le falta un valor requerido.',
    'current_password' => 'La contraseña es incorrecta.',
    'date' => 'El campo :attribute no es una fecha válida.',
    'date_equals' => 'El campo :attribute debe ser la fecha :date.',
    'date_format' => 'El campo :attribute no coincide con el formato :format.',
    'decimal' => 'El campo :attribute debe tener :decimal decimales.',
    'declined' => 'El campo :attribute debe ser rechazado.',
    'declined_if' => 'El campo :attribute debe ser rechazado cuando :other es :value.',
    'different' => 'El campo :attribute debe ser diferente de :other.',
    'digits' => 'El campo :attribute debe tener :digits dígitos.',
    'digits_between' => 'El campo :attribute debe tener entre :min y :max dígitos.',
    'dimensions' => 'La imagen tiene dimensiones de imagen no válidas.',
    'distinct' => 'El valor del campo :attribute es repetido.',
    'doesnt_end_with' => 'El campo :attribute no debe terminar con: :values.',
    'doesnt_start_with' => 'El campo :attribute no debe empezar con: :values.',
    'email' => 'El campo :attribute debe ser una dirección de correo válida.',
    'encoding' => 'El campo :attribute debe tener la codificación :encoding.',
    'ends_with' => 'El campo :attribute debe terminar con: :values.',
    'enum' => 'El valor seleccionado para :attribute no es válido.',
    'exists' => 'El campo :attribute seleccionado no es válido.',
    'extensions' => 'El campo :attribute debe tener una de las siguientes extensiones: :values.',
    'file' => 'El campo :attribute debe ser un archivo.',
    'filled' => 'El campo :attribute no puede estar vacío.',
    'gt' => [
        'array' => 'El campo :attribute debe tener más de :value elementos.',
        'file' => 'El campo :attribute debe pesar más de :value kilobytes.',
        'numeric' => 'El campo :attribute debe ser mayor que :value.',
        'string' => 'El campo :attribute debe tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => 'El campo :attribute debe tener :value elementos o más.',
        'file' => 'El campo :attribute debe pesar :value kilobytes o más.',
        'numeric' => 'El campo :attribute debe ser mayor o igual que :value.',
        'string' => 'El campo :attribute debe tener :value caracteres o más.',
    ],
    'hex_color' => 'El campo :attribute debe ser un color hexadecimal válido.',
    'image' => 'El campo :attribute debe ser una imagen.',
    'in' => 'El campo :attribute seleccionado no es válido.',
    'in_array' => 'El campo :attribute no existe en :other.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'ip' => 'El campo :attribute debe ser una dirección IP válida.',
    'ipv4' => 'El campo :attribute debe ser una dirección IPv4 válida.',
    'ipv6' => 'El campo :attribute debe ser una dirección IPv6 válida.',
    'json' => 'El campo :attribute debe ser un texto JSON válido.',
    'list' => 'El campo :attribute debe ser una lista.',
    'lowercase' => 'El campo :attribute debe estar en minúsculas.',
    'lt' => [
        'array' => 'El campo :attribute debe tener menos de :value elementos.',
        'file' => 'El campo :attribute debe pesar menos de :value kilobytes.',
        'numeric' => 'El campo :attribute debe ser menor que :value.',
        'string' => 'El campo :attribute debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => 'El campo :attribute no puede tener más de :value elementos.',
        'file' => 'El campo :attribute debe pesar :value kilobytes o menos.',
        'numeric' => 'El campo :attribute debe ser menor o igual que :value.',
        'string' => 'El campo :attribute debe tener :value caracteres o menos.',
    ],
    'mac_address' => 'El campo :attribute debe ser una dirección MAC válida.',
    'max' => [
        'array' => 'El campo :attribute no puede tener más de :max elementos.',
        'file' => 'El campo :attribute no puede pesar más de :max kilobytes.',
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'string' => 'El campo :attribute no puede tener más de :max caracteres.',
    ],
    'max_digits' => 'El campo :attribute no debe tener más de :max dígitos.',
    'mimes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'array' => 'El campo :attribute debe tener al menos :min elementos.',
        'file' => 'El campo :attribute debe pesar al menos :min kilobytes.',
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
    ],
    'min_digits' => 'El campo :attribute debe tener al menos :min dígitos.',
    'missing' => 'El campo :attribute no debe estar presente.',
    'missing_if' => 'El campo :attribute no debe estar presente cuando :other es :value.',
    'missing_unless' => 'El campo :attribute no debe estar presente a menos que :other sea :values.',
    'missing_with' => 'El campo :attribute no debe estar presente cuando hay :values.',
    'missing_with_all' => 'El campo :attribute no debe estar presente cuando hay :values.',
    'multiple_of' => 'El campo :attribute debe ser múltiplo de :value.',
    'not_in' => 'El campo :attribute seleccionado no es válido.',
    'not_regex' => 'El formato del campo :attribute no es válido.',
    'numeric' => 'El campo :attribute debe ser un número.',
    'present' => 'El campo :attribute debe estar presente.',
    'present_if' => 'El campo :attribute debe estar presente cuando :other es :value.',
    'present_unless' => 'El campo :attribute debe estar presente a menos que :other sea :values.',
    'present_with' => 'El campo :attribute debe estar presente cuando hay :values.',
    'present_with_all' => 'El campo :attribute debe estar presente cuando hay :values.',
    'prohibited' => 'El campo :attribute está prohibido.',
    'prohibited_if' => 'El campo :attribute está prohibido cuando :other es :value.',
    'prohibited_if_accepted' => 'El campo :attribute está prohibido cuando :other es aceptado.',
    'prohibited_if_declined' => 'El campo :attribute está prohibido cuando :other es rechazado.',
    'prohibited_unless' => 'El campo :attribute está prohibido a menos que :other sea :values.',
    'prohibits' => 'El campo :attribute prohíbe que :other tenga un valor.',
    'regex' => 'El formato del campo :attribute no es válido.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_array_keys' => 'Al campo :attribute le faltan las claves: :values.',
    'required_if' => 'El campo :attribute es obligatorio cuando :other es :value.',
    'required_if_accepted' => 'El campo :attribute es obligatorio cuando :other es aceptado.',
    'required_if_declined' => 'El campo :attribute es obligatorio cuando :other es rechazado.',
    'required_unless' => 'El campo :attribute es obligatorio a menos que :other sea :values.',
    'required_with' => 'El campo :attribute es obligatorio cuando hay :values.',
    'required_with_all' => 'El campo :attribute es obligatorio cuando hay :values.',
    'required_without' => 'El campo :attribute es obligatorio cuando no hay :values.',
    'required_without_all' => 'El campo :attribute es obligatorio cuando no hay ninguno de :values.',
    'same' => 'Los campos :attribute y :other deben coincidir.',
    'size' => [
        'array' => 'El campo :attribute debe tener :size elementos.',
        'file' => 'El campo :attribute debe pesar :size kilobytes.',
        'numeric' => 'El campo :attribute debe ser :size.',
        'string' => 'El campo :attribute debe tener :size caracteres.',
    ],
    'starts_with' => 'El campo :attribute debe empezar con: :values.',
    'string' => 'El campo :attribute debe ser texto.',
    'timezone' => 'El campo :attribute debe ser una zona horaria válida.',
    'unique' => 'El campo :attribute ya está en uso.',
    'uploaded' => 'No se pudo subir el campo :attribute.',
    'uppercase' => 'El campo :attribute debe estar en mayúsculas.',
    'url' => 'El campo :attribute debe ser una URL válida.',
    'ulid' => 'El campo :attribute debe ser un ULID válido.',
    'uuid' => 'El campo :attribute debe ser un UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Mensajes propios de la app
    |--------------------------------------------------------------------------
    |
    | Los nombres de campo llegan en ingles porque los codigos y los tests no
    | cambian de idioma. Estas lineas son las unicas que se muestran al usuario,
    | y ya salen en castellano.
    |
    */

    'custom' => [
        'identifier' => [
            // La pantalla rotula el campo como DNI, asi que el error tiene que
            // hablar del DNI. El backend sigue aceptando email, pero no hace
            // falta anunciarlo en un error de campo vacio.
            'required' => 'Escribí tu DNI.',
        ],
        'password' => [
            'min' => 'La contraseña es muy corta.',
        ],
        'consent' => [
            'accepted' => 'Tenés que aceptar para poder comprar.',
        ],
        'items' => [
            'required' => 'No hay nada para guardar.',
        ],
        'items.*.id' => [
            'required' => 'Hay una fila sin identificar.',
            'exists' => 'Uno de los campos ya no existe.',
        ],
        'items.*.value' => [
            'required' => 'El valor no puede estar vacío.',
        ],
    ],

    'attributes' => [
        'identifier' => 'DNI',
        'password' => 'contraseña',
        'password_confirmation' => 'confirmación de contraseña',
        'current_password' => 'contraseña actual',
        'remember' => 'recordarme',
        'name' => 'nombre',
        'email' => 'correo',
        'dni' => 'DNI',
        'phone' => 'teléfono',
        'consent' => 'consentimiento',
        'event_id' => 'evento',
        'ticket_type_id' => 'tipo de entrada',
        'type_id' => 'tipo de entrada',
        'order_id' => 'orden',
        'ticket_id' => 'entrada',
        'ad_id' => 'publicidad',
        'items' => 'campos',
        'items.*.id' => 'campo',
        'items.*.value' => 'valor',
        'capacity' => 'capacidad',
        'price' => 'precio',
        'stock' => 'stock',
        'max_per_order' => 'máximo por compra',
        'max_installments' => 'cantidad de cuotas',
        'payment_mode' => 'modo de pago',
        'starts_at' => 'fecha de inicio',
        'ends_at' => 'fecha de fin',
        'sale_starts_at' => 'inicio de la venta',
        'sale_ends_at' => 'fin de la venta',
        'location' => 'lugar',
        'status' => 'estado',
        'position' => 'posición',
        'sort_order' => 'orden',
        'image' => 'imagen',
        'image_path' => 'imagen',
        'url' => 'enlace',
        'enable' => 'visibilidad',
        'is_admin' => 'acceso de administrador',
        'total' => 'total',
        'amount' => 'importe',
    ],

];