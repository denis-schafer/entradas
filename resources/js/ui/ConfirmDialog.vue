<script setup>
/**
 * Confirmacion con el estilo del sistema (el mismo modal que usa el panel).
 *
 * Se monta una sola vez en la raiz y se maneja desde confirm.js, asi que
 * cualquier pantalla puede pedir una confirmacion sin declarar props ni estado.
 * Reemplaza a window.confirm, que rompe con el diseno.
 */
import { confirmState, resolveConfirm } from './confirm.js';

const ICONS = {
    primary: 'bi-question-circle',
    danger: 'bi-exclamation-triangle',
    warning: 'bi-exclamation-triangle',
    success: 'bi-check-circle',
};
</script>

<template>
    <div
        v-if="confirmState.open"
        class="modal-backdrop"
        @click.self="resolveConfirm(false)"
    >
        <div class="modal-sheet" role="dialog" aria-modal="true">
            <header class="modal-sheet__head">
                <h3 class="h6 fw-bold mb-0">
                    <i class="bi me-2" :class="ICONS[confirmState.tone] || ICONS.primary"></i>{{ confirmState.title }}
                </h3>
                <button class="btn btn-link btn-sm p-0" @click="resolveConfirm(false)">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>

            <div class="modal-sheet__body">
                <p class="mb-0" style="white-space: pre-line">{{ confirmState.message }}</p>

                <div class="modal-sheet__foot">
                    <button class="btn btn-et-ghost" @click="resolveConfirm(false)">
                        {{ confirmState.cancelLabel }}
                    </button>
                    <button
                        class="btn"
                        :class="confirmState.tone === 'danger' ? 'btn-et-danger' : 'btn-et-primary'"
                        @click="resolveConfirm(true)"
                    >
                        {{ confirmState.confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
