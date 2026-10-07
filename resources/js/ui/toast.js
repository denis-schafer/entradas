import { ref } from 'vue';

/**
 * Avisos efimeros en un modulo aparte del componente.
 *
 * Vive aqui y no dentro del componente porque los avisos se emiten desde
 * cualquier capa (un controlador de pantalla, el cliente HTTP, un watcher) sin
 * que haga falta pasar props hacia abajo. El componente solo los pinta.
 */
const items = ref([]);

let seq = 0;

const LIFE_MS = 4500;

export function toast(message, tone = 'info', timeout = LIFE_MS) {
    const id = ++seq;

    items.value = [...items.value, { id, message, tone }];

    setTimeout(() => dismiss(id), timeout);

    return id;
}

export function dismiss(id) {
    items.value = items.value.filter((t) => t.id !== id);
}

export const toasts = items;

export const TONE_ICON = {
    success: 'bi-check-circle-fill',
    danger: 'bi-exclamation-octagon-fill',
    warning: 'bi-exclamation-triangle-fill',
    info: 'bi-info-circle-fill',
};

export default toast;