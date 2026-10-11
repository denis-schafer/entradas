<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function admin(array $overrides = []): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => $overrides['email'] ?? 'admin@test.local',
            'password' => 'secreto123',
            'is_admin' => true,
            // Sin esto User::create deja role en NULL (Eloquent no lee los
            // DEFAULT de la base) y el usuario no seria ni admin ni cajero.
            'role' => User::ROLE_ADMIN,
            ...$overrides,
        ]);
    }

    /**
     * Operador de la puerta: entra al panel, pero solo escanea.
     */
    protected function cashier(array $overrides = []): User
    {
        return User::create([
            'name' => 'Cajero',
            'email' => $overrides['email'] ?? 'cajero@test.local',
            'password' => 'secreto123',
            'is_admin' => true,
            'role' => User::ROLE_CASHIER,
            ...$overrides,
        ]);
    }

    /**
     * Vendedor de la caja: solo vende en efectivo desde el POS, acotado a los
     * eventos que tenga asignados.
     */
    protected function seller(array $overrides = []): User
    {
        return User::create([
            'name' => 'Vendedor',
            'email' => $overrides['email'] ?? 'vendedor@test.local',
            'password' => 'secreto123',
            'is_admin' => true,
            'role' => User::ROLE_SELLER,
            ...$overrides,
        ]);
    }

    /**
     * Administrativo: estadisticas de sus eventos, alta de vendedores y venta.
     */
    protected function administrativo(array $overrides = []): User
    {
        return User::create([
            'name' => 'Administrativo',
            'email' => $overrides['email'] ?? 'administrativo@test.local',
            'password' => 'secreto123',
            'is_admin' => true,
            'role' => User::ROLE_ADMINISTRATIVO,
            ...$overrides,
        ]);
    }

    /**
     * Asigna eventos del pivote a un operador acotado.
     */
    protected function assignEvents(User $user, array $eventIds): void
    {
        foreach ($eventIds as $eventId) {
            \Illuminate\Support\Facades\DB::table('tickets_event_user')->insertOrIgnore([
                'event_id' => $eventId,
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function buyer(array $overrides = []): User
    {
        return User::create([
            'name' => 'Comprador',
            'email' => $overrides['email'] ?? 'comprador@test.local',
            'dni' => $overrides['dni'] ?? '29923360',
            'password' => 'secreto123',
            'is_admin' => false,
            ...$overrides,
        ]);
    }
}