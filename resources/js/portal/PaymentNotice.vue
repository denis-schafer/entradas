<script setup>
/**
 * Aviso global de pago confirmado.
 *
 * Vive en el shell del portal (no en una pantalla puntual) porque el pago
 * puede acreditarse mientras el comprador esta en cualquier vista: si salto a
 * pagar en otra pestana y volvio, el aviso tiene que aparecer igual.
 *
 * Como funciona:
 *   - Pide las ordenes del comprador y recuerda el estado de cada una.
 *   - Se suscribe al canal privado de cada orden pendiente, asi el webhook
 *     dispara el refresco al instante; el polling de respaldo cubre el caso
 *     de Reverb caido.
 *   - Cuando una orden pasa de 'pending' a 'paid' abre el modal.
 *
 * Cerrar el modal (boton, X o fondo) lleva al comprador a "Mis entradas".
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import api from '../api.js';
import { listen } from '../realtime.js';
import { forgetOrder } from '../shell/router.js';

const emit = defineEmits(['navigate']);

const paidOrder = ref(null);

const statusById = new Map();
const subs = new Map();

/*
| Ordenes ya avisadas. Se guarda en sessionStorage para que recargar la pagina
| justo despues de pagar no vuelva a abrir el modal. Ademas se limpia la orden
| pendiente guardada para que la recarga no caiga de nuevo en el resultado.
*/
const ACK_KEY = 'et:paid-notice-ack';
const RECENT_MS = 2 * 60 * 1000;

const ack = loadAck();

function loadAck() {
    try {
        const raw = sessionStorage.getItem(ACK_KEY);

        return new Set(raw ? JSON.parse(raw) : []);
    } catch {
        return new Set();
    }
}

function persistAck() {
    try {
        sessionStorage.setItem(ACK_KEY, JSON.stringify([...ack]));
    } catch {
        // sin storage el aviso igual funciona: a lo sumo se repite tras recargar.
    }
}

function isRecent(order) {
    if (! order.paid_at) {
        return false;
    }

    const paidAt = Date.parse(order.paid_at);

    return Number.isFinite(paidAt) && Date.now() - paidAt < RECENT_MS;
}

function announce(order) {
    ack.add(order.id);
    persistAck();

    if (! paidOrder.value) {
        paidOrder.value = order;
    }

    forgetOrder(order.id);
}

async function check() {
    try {
        const { data } = await api.get('tickets-portal/api/my-orders');

        const list = data || [];

        list.forEach((order) => {
            const prev = statusById.get(order.id);

            statusById.set(order.id, order.status);

            if (order.status === 'paid' && ! ack.has(order.id)) {
                // Transicion observada en vivo (estaba pendiente) o pago recien
                // acreditado que recien vemos (por ejemplo al volver de MP).
                if (prev === 'pending' || isRecent(order)) {
                    announce(order);
                } else {
                    ack.add(order.id);
                    persistAck();
                }
            }

            if (order.status === 'pending' && order.public_token && ! subs.has(order.public_token)) {
                subs.set(
                    order.public_token,
                    listen(`tickets.order.${order.public_token}`, 'order.status', check)
                );
            }
        });

        const stillPending = new Set(
            list
                .filter((order) => order.status === 'pending' && order.public_token)
                .map((order) => order.public_token)
        );

        [...subs.keys()].forEach((token) => {
            if (! stillPending.has(token)) {
                subs.get(token)?.();
                subs.delete(token);
            }
        });
    } catch {
        // Silencioso a proposito: el polling reintenta. Este aviso nunca debe
        // interrumpir lo que el comprador este haciendo.
    }
}

function close() {
    paidOrder.value = null;
    emit('navigate', 'my-tickets');
}

function onOrderCreated() {
    check();
}

// Intervalo propio, no el startPolling compartido: ese es un singleton y lo
// pisa la pantalla que se monte despues. El aviso global tiene que seguir
// corriendo sin importar la vista activa.
const POLL_MS = 15000;
let timer = null;

onMounted(() => {
    check();

    timer = setInterval(() => {
        if (! document.hidden) {
            check();
        }
    }, POLL_MS);

    window.addEventListener('et:order-created', onOrderCreated);
});

onBeforeUnmount(() => {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }

    window.removeEventListener('et:order-created', onOrderCreated);

    subs.forEach((off) => off?.());
    subs.clear();
});
</script>

<template>
    <Teleport to="body">
        <div v-if="paidOrder" class="paid-notice no-print" @click.self="close">
            <div class="paid-notice__card et-surface-raised" role="dialog" aria-modal="true">
                <button type="button" class="paid-notice__close" aria-label="Cerrar" @click="close">
                    <i class="bi bi-x-lg"></i>
                </button>

                <div class="paid-notice__icon">
                    <i class="bi bi-check-circle-fill"></i>
                </div>

                <h2 class="h5 fw-bold mb-2">¡Pago confirmado!</h2>
                <p class="text-muted-2 mb-4">
                    Tu compra de {{ paidOrder.event_name || 'el evento' }} se acreditó.
                    Ya podés ver y descargar tus entradas.
                </p>

                <button class="btn btn-et-primary" @click="close">
                    <i class="bi bi-qr-code me-1"></i>Ver mis entradas
                </button>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.paid-notice {
    position: fixed;
    inset: 0;
    z-index: 1200;
    display: grid;
    place-items: center;
    padding: 1rem;
    background: rgb(0 0 0 / 58%);
    backdrop-filter: blur(3px);
}

.paid-notice__card {
    position: relative;
    width: min(420px, 100%);
    padding: 2.25rem 1.75rem 1.75rem;
    border-radius: var(--et-radius-lg);
    text-align: center;
    animation: paid-pop 200ms ease-out;
}

.paid-notice__close {
    position: absolute;
    top: 0.6rem;
    right: 0.6rem;
    border: 0;
    background: none;
    color: var(--et-text-faint);
    font-size: 1rem;
    line-height: 1;
    padding: 0.4rem;
}

.paid-notice__close:hover {
    color: var(--et-text);
}

.paid-notice__icon {
    width: 68px;
    height: 68px;
    margin: 0 auto 1rem;
    border-radius: 50%;
    display: grid;
    place-items: center;
    font-size: 2rem;
    background: rgb(46 213 115 / 14%);
    color: var(--et-success);
}

@keyframes paid-pop {
    from {
        opacity: 0;
        transform: translateY(12px) scale(0.97);
    }

    to {
        opacity: 1;
        transform: none;
    }
}
</style>
