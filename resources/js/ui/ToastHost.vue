<script setup>
/**
 * Host de avisos. Se monta una sola vez, en el shell activo, y se apoya en el
 * store de toast.js para que cualquier capa pueda emitir sin cablearlo.
 */
import { toasts, dismiss, TONE_ICON } from './toast.js';
</script>

<template>
    <div class="toast-stack no-print" aria-live="polite" aria-atomic="true">
        <div
            v-for="t in toasts"
            :key="t.id"
            class="toast-card"
            :class="`toast-card--${t.tone}`"
            role="status"
        >
            <i class="bi" :class="TONE_ICON[t.tone]"></i>
            <span>{{ t.message }}</span>
            <button class="toast-close" @click="dismiss(t.id)" aria-label="Cerrar aviso">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </div>
</template>

<style scoped>
.toast-stack {
    position: fixed;
    bottom: 1.25rem;
    right: 1.25rem;
    z-index: 1080;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-width: min(380px, calc(100vw - 2.5rem));
}

.toast-card {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    padding: 0.7rem 0.85rem;
    border-radius: var(--et-radius-sm);
    border: 1px solid var(--et-border-strong);
    background: var(--et-bg-elevated);
    box-shadow: var(--et-shadow);
    font-size: 0.875rem;
    animation: et-enter var(--et-transition) both;
}

.toast-card--success { border-color: var(--et-success); color: var(--et-success); }
.toast-card--danger { border-color: var(--et-danger); color: var(--et-danger); }
.toast-card--warning { border-color: var(--et-warning); color: var(--et-warning); }
.toast-card--info { border-color: var(--et-info); color: var(--et-info); }

.toast-card span {
    color: var(--et-text);
}

.toast-close {
    margin-left: auto;
    background: none;
    border: 0;
    color: var(--et-text-faint);
    padding: 0 0.15rem;
    line-height: 1;
}
</style>