<script setup>
/** Indicador de conexion del websocket. */
import { reactive } from 'vue';
import { realtimeState } from '../realtime.js';

const state = reactive(realtimeState);
</script>

<template>
    <span
        class="et-badge"
        :class="state.connected ? 'et-badge--success' : 'et-badge--muted'"
        :title="state.connected
            ? 'Conectado en tiempo real'
            : 'Sin websocket: los datos se actualizan cada 15 segundos'"
    >
        <span
            class="d-inline-block rounded-circle"
            :style="{ width: '6px', height: '6px', background: 'currentColor' }"
        />
        {{ state.connected ? 'En vivo' : 'Sincronizando' }}
    </span>
</template>