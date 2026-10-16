<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /*
    | Roles del panel. Comprador no esta en la lista: un comprador no es
    | is_admin, asi que ni siquiera llega al middleware del panel.
    */
    public const ROLE_ADMIN = 'admin';

    public const ROLE_CASHIER = 'cajero';

    /*
    | Administrativo: mira las estadisticas del evento al que esta asignado, da
    | de alta a los vendedores y vende desde el POS. No toca la configuracion
    | global (eventos, medios de pago, publicidad, configuracion).
    */
    public const ROLE_ADMINISTRATIVO = 'administrativo';

    /*
    | Vendedor: solo vende en efectivo desde el POS. Sin escaner y sin panel de
    | administracion. Se limita a los eventos que tiene asignados.
    */
    public const ROLE_SELLER = 'vendedor';

    protected $fillable = [
        'name',
        'email',
        'dni',
        'phone',
        'password',
        'is_admin',
        'role',
        'enable',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Eloquent no lee los DEFAULT de la base al crear, asi que un User::create
     * sin estos campos queda con atributos null en memoria aunque en la tabla
     * valgan true. El middleware de sesion mira enable e is_admin, y ahi un
     * null se lee como "deshabilitado": un usuario recien creado se rechazaba.
     */
    protected $attributes = [
        'is_admin' => false,
        'role' => self::ROLE_ADMIN,
        'enable' => true,
        'must_change_password' => false,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'consent_accepted_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'enable' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * El DNI se compara siempre como string de 8 a 10 digitos, sin guiones.
     * Ver TicketPortalAuthController::normalizeDni().
     */
    public function scopeWithDni($query, ?string $dni)
    {
        return $query->where('dni', $dni);
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * Rol efectivo del operador.
     *
     * role en NULL se interpreta como admin a proposito. Todos los operadores
     * que ya existian antes de que existiera el rol quedaron con NULL, y
     * tratarlos de otra forma les habria borrado el acceso de un dia para otro
     * sin que nadie lo pidiera.
     */
    public function roleName(): string
    {
        return $this->role ?: self::ROLE_ADMIN;
    }

    /**
     * Administrador: ve el panel entero. Es el unico que puede configurar el
     * sistema (eventos, medios de pago, publicidad, compradores).
     */
    public function isStaffAdmin(): bool
    {
        return $this->is_admin && $this->roleName() === self::ROLE_ADMIN;
    }

    /**
     * Cajero: la persona de la puerta. Escanea entradas, entrega pulseras y ve
     * el historial de lo que escaneo. Nada mas.
     */
    public function isCashier(): bool
    {
        return $this->is_admin && $this->roleName() === self::ROLE_CASHIER;
    }

    /**
     * Administrativo: estadisticas de su evento, alta de vendedores y venta.
     */
    public function isAdministrativo(): bool
    {
        return $this->is_admin && $this->roleName() === self::ROLE_ADMINISTRATIVO;
    }

    /**
     * Vendedor: solo vende en efectivo desde el POS.
     */
    public function isSeller(): bool
    {
        return $this->is_admin && $this->roleName() === self::ROLE_SELLER;
    }

    /**
     * Puede usar el POS (vender). El administrador tambien, porque ve todo.
     */
    public function isPosUser(): bool
    {
        return $this->is_admin && in_array($this->roleName(), [
            self::ROLE_ADMIN,
            self::ROLE_ADMINISTRATIVO,
            self::ROLE_SELLER,
        ], true);
    }

    /**
     * Puede gestionar usuarios (al menos vendedores). El cajero y el vendedor
     * no.
     */
    public function canManageUsers(): bool
    {
        return $this->is_admin && in_array($this->roleName(), [
            self::ROLE_ADMIN,
            self::ROLE_ADMINISTRATIVO,
        ], true);
    }

    /**
     * Lo unico que puede hacer cada rol, en terminos de rutas del panel.
     *
     * Vive aca y no en el middleware para que el menu del frontend pueda usar
     * exactamente la misma lista: si se definieran en dos lugares, el menu
     * llegaria a mostrarle algo que despues el servidor le rechaza con 403.
     */
    public function allowedPanelRoutes(): array
    {
        return match ($this->roleName()) {
            self::ROLE_CASHIER => [
                'scanner',
                'scans',
                'password',
            ],
            self::ROLE_SELLER => [
                'pos',
                'password',
            ],
            self::ROLE_ADMINISTRATIVO => [
                'statistics',
                'users',
                'pos',
                'raffles',
                'password',
            ],
            default => ['*'],
        };
    }

    public function canAccessPanelRoute(string $route): bool
    {
        $allowed = $this->allowedPanelRoutes();

        return in_array('*', $allowed, true) || in_array($route, $allowed, true);
    }

    /**
     * IDs de los eventos asignados a este usuario (tickets_event_user).
     *
     * Es la base del limite de un cajero: solo ve, filtra y escanea estos
     * eventos. Un cajero sin asignaciones devuelve [] y, por lo tanto, no
     * ve ni escanea nada. El administrador no se limita con esta lista.
     */
    public function assignedEventIds(): array
    {
        return DB::table('tickets_event_user')
            ->where('user_id', $this->id)
            ->pluck('event_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * IDs de los medios de pago que este operador puede cobrar desde el POS
     * (tickets_user_payment_method). Lista vacia = solo efectivo.
     */
    public function paymentMethodIds(): array
    {
        return DB::table('tickets_user_payment_method')
            ->where('user_id', $this->id)
            ->pluck('payment_method_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Codes de los medios de pago asignados a este operador. El administrador
     * no consulta esto: usa todos los medios habilitados del evento.
     */
    public function allowedPaymentMethodCodes(): array
    {
        return DB::table('tickets_user_payment_method as up')
            ->join('tickets_payment_methods as pm', 'pm.id', '=', 'up.payment_method_id')
            ->where('up.user_id', $this->id)
            ->pluck('pm.code')
            ->all();
    }
}