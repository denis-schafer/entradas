import { reactive } from 'vue';

/**
 * Confirmacion en un modulo aparte, igual que los avisos (toast.js).
 *
 * Las pantallas llaman a confirm({...}) y esperan un booleano con await, sin
 * montar ni manejar estado de modal cada una. El componente ConfirmDialog solo
 * pinta lo que hay en este estado y resuelve la promesa.
 */
const state = reactive({
    open: false,
    title: '',
    message: '',
    confirmLabel: 'Confirmar',
    cancelLabel: 'Cancelar',
    tone: 'primary',
});

export const confirmState = state;

// Resolucion de la promesa pendiente. Solo hay una confirmacion a la vez.
let resolver = null;

export function confirm(options = {}) {
    // Si habia una confirmacion abierta, se descarta: gana la ultima.
    settle(false);

    state.title = options.title || 'Confirmar';
    state.message = options.message || '';
    state.confirmLabel = options.confirmLabel || 'Confirmar';
    state.cancelLabel = options.cancelLabel || 'Cancelar';
    state.tone = options.tone || 'primary';
    state.open = true;

    return new Promise((resolve) => {
        resolver = resolve;
    });
}

export function resolveConfirm(value) {
    settle(Boolean(value));
}

function settle(value) {
    state.open = false;

    const resolve = resolver;
    resolver = null;

    if (resolve) {
        resolve(value);
    }
}

export default confirm;
