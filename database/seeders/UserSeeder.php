<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Administrador inicial.
 *
 * Solo se crea si no hay ningun admin: en una base ya en uso el seeder no
 * vuelve a tocar usuarios. La contrasena sale de MP_ADMIN_PASSWORD y, si no
 * esta definida, se genera una aleatoria que se imprime una unica vez.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $hasAdmin = DB::table('users')->where('is_admin', true)->exists();

        if ($hasAdmin) {
            $this->command?->info('Ya existe un administrador: no se creo ninguno nuevo.');

            return;
        }

        $email = env('MP_ADMIN_EMAIL', 'admin@entradas.test');
        $generated = false;

        $password = env('MP_ADMIN_PASSWORD');

        if (! $password) {
            $password = Str::password(16);
            $generated = true;
        }

        DB::table('users')->insert([
            'name' => env('MP_ADMIN_NAME', 'Administrador'),
            'email' => $email,
            'password' => Hash::make($password),
            'is_admin' => true,
            'enable' => true,
            'must_change_password' => $generated,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command?->info("Admin creado: {$email}");

        if ($generated) {
            $this->command?->warn("Contrasena generada: {$password}");
            $this->command?->warn('Guardala: no se vuelve a mostrar y el panel la pide cambiar al primer ingreso.');
        }
    }
}