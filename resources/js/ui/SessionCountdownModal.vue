<script setup>
/**
 * Aviso de sesion por vencer.
 *
 * Aparece cuando a la sesion le quedan diez minutos o menos y ofrece renovarla
 * sin perder lo que se esta haciendo: cualquier request desliza la cookie en el
 * servidor, asi que basta con pedirle que confirme que la sesion sigue viva.
 *
 * Se monta una sola vez en la raiz y sirve igual para el panel y el portal,
 * porque los dos comparten la misma sesion de Laravel.
 */
import { computed, ref } from 'vue';
import api from '../api.js';
import { sessionState, touchSession } from '../sessionWatch.js';
import { toast } from './toast.js';

const emit = defineEmits(['logout']);

const busy = ref(false);

const timeLabel = computed(() => {
    const total = sessionState.value.remaining;
    const minutes = String(Math.floor(total / 60)).padStart(2, '0');
    const seconds = String(total % 60).padStart(2, '0');

    return `${minutes}:${seconds}`;
});

async function keepAlive() {
    if (busy.value) {
        return;
    }

    busy.value = true;

    try {
        await api.post('session/refresh');
        touchSession();
        toast('Sesion renovada.', 'success');
    } catch {
        toast('No se pudo renovar la sesion.', 'danger');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div v-if="sessionState.warning" class="modal-backdrop" @click.self="keepAlive">
        <div class="modal-sheet">
            <div class="modal-sheet__head">
                <h2 class="h6 mb-0">
                    <i class="bi bi-hourglass-split me-2"></i>Tu sesion esta por vencer
                </h2>
            </div>

            <div class="modal-sheet__body">
                <p class="mb-2">
                    Por seguridad, la sesion se cierra sola tras un rato sin
                    actividad. Vence en:
                </p>

                <p class="session-countdown">{{ timeLabel }}</p>

                <p class="text-muted-2 small mb-0">
                    Si estas en el medio de una carga, elegi mantener la sesion
                    activa para no perder los datos.
                </p>

                <div class="modal-sheet__foot">
                    <button class="btn btn-et-ghost" :disabled="busy" @click="emit('logout')">
                        Cerrar sesion
                    </button>
                    <button class="btn btn-et-primary" :disabled="busy" @click="keepAlive">
                        <span v-if="busy" class="spinner-border spinner-border-sm me-2"></span>
                        Mantener sesion activa
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.session-countdown {
    font-size: 2rem;
    font-weight: 700;
    text-align: center;
    letter-spacing: 0.05em;
    color: var(--et-warning);
}
</style>
