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