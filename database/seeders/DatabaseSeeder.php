<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Datos iniciales de una instalacion nueva.
 *
 * Todos los seeders usan updateOrInsert por clave natural en vez de truncate o
 * delete: correrlos de nuevo no rompe nada, y nunca borran lo que ya esta.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TicketsConfigSeeder::class,
            TicketsPaymentMethodSeeder::class,
            UserSeeder::class,
        ]);
    }
}