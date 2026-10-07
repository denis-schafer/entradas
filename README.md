# entradas

Sistema de venta de entradas para eventos y recitales. Laravel 12 + Vue 3, con cobros
via MercadoPago (Checkout Pro, OAuth por evento) y escaneo de QR en la puerta.

## Stack

- PHP 8.2, Laravel 12
- Frontend Vue 3 con Vite, Bootstrap 5, Pusher/Reverb
- MySQL/MariaDB
- BaconQrCode (QR), ZXing + jsQR (scanner), Dompdf si lo agregamos despues

## Setup local

```bash
# Dependencias
composer install
npm install

# Entorno
cp .env.example .env
php artisan key:generate

# DB
php artisan migrate

# Build
npm run build

# Servir
php artisan serve
```

## Roles

- **Administrador**: panel completo
- **Cajero**: solo escanear y entregar pulseras

## Notas

- Cada evento cobra con su propia cuenta de MP (OAuth por organizador).
- El QR del boleto se firma HMAC contra un `qr_secret` guardado en `tickets_configs`.
- Webhook de MP: `/tickets/mp/webhook/{event_id}`. Hay un boton "Reconciliar" en el
  admin para los casos donde el webhook no llego (entorno local).