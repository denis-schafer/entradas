<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
     * Administrador: ve el panel entero.
     *
     * role en NULL se interpreta como admin a proposito. Todos los operadores
     * que ya existian antes de que existiera el rol quedaron con NULL, y tratarlos
     * como cajeros les habria borrado el acceso de un dia para otro sin que nadie
     * lo pidiera.
     */
    public function isStaffAdmin(): bool
    {
        return $this->is_admin && $this->role !== self::ROLE_CASHIER;
    }

    /**
     * Cajero: la persona de la puerta. Escanea entradas, entrega pulseras y ve
     * el historial de lo que escaneo. Nada mas.
     */
    public function isCashier(): bool
    {
        return $this->is_admin && $this->role === self::ROLE_CASHIER;
    }

    /**
     * Lo unico que puede hacer un cajero, en terminos de rutas del panel.
     *
     * Vive aca y no en el middleware para que el menu del frontend pueda usar
     * exactamente la misma lista: si se definieran en dos lugares, el menu
     * llegaria a mostrarle algo que despues el servidor le rechaza con 403.
     */
    public function allowedPanelRoutes(): array
    {
        if ($this->isCashier()) {
            return [
                'scanner',
                'scans',
                'password',
            ];
        }

        return ['*'];
    }

    public function canAccessPanelRoute(string $route): bool
    {
        $allowed = $this->allowedPanelRoutes();

        return in_array('*', $allowed, true) || in_array($route, $allowed, true);
    }
}