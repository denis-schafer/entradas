/*
|--------------------------------------------------------------------------
| Router interno
|--------------------------------------------------------------------------
|
| La app vive entera en http://entradas.test. No hay vue-router, ni pushState,
| ni fragmentos: la "ruta" es un string guardado en memoria y la URL no se
| toca nunca. Cambiar de pantalla es cambiar ese string, que el shell observa
| para montar y desmontar el componente correspondiente.
|
| Por que no vue-router: cualquier router de History API escribe en la barra
| de direcciones, y el requisito es que la barra no cambie. Un hash router
| evitaria las rutas pero deja "#/mis-entradas" a la vista, que es lo mismo
| que un path. Ademas el retorno de MercadoPago llega con un fragmento, y un
| fragmento que uno controla se confunde con el del router.
|
| Si alguna vez hace falta deep link, se resuelve con sessionStorage: el
| backend guarda el destino y el frontend lo consume una vez. Asi la URL
| sigue siendo la misma de siempre.
|
| Cada shell registra su propio mapa con defineRoutes() al montarse, para que
| un typo en un go() falle en desarrollo y no se pinsa una pantalla vacia.
*/

import { reactive, computed } from 'vue';

const state = reactive({
    name: 'loading',
    params: {},
});

const listeners = new Set();

function commit(name, params = {}) {
    if (state.name === name && shallowEqual(state.params, params)) {
        return;
    }

    state.name = name;
    state.params = params;

    listeners.forEach((fn) => fn(state));
}

function shallowEqual(a, b) {
    const ka = Object.keys(a);
    const kb = Object.keys(b);

    return ka.length === kb.length && ka.every((k) => a[k] === b[k]);
}

export const routes = {};

export function defineRoutes(map) {
    Object.assign(routes, map);
}

/**
 * Navega dentro de la app. No escribe nada en la barra de direcciones.
 */
export function go(name, params = {}) {
    if (!routes[name]) {
        throw new Error(`Ruta desconocida: "${name}"`);
    }

    commit(name, params);
}

export function current() {
    return state.name;
}

export function currentParams() {
    return state.params;
}

export function onNavigate(fn) {
    listeners.add(fn);

    return () => listeners.delete(fn);
}

/* ---------------------------------------------------------------------------
| La orden que esta esperando el pago
| ---------------------------------------------------------------------------
|
| El comprador crea la orden y recien ahi se la guarda en sessionStorage. Al
| saltar a mercadopago.com y volver, la pestana conserva el storage de
| entradas.test, asi que el frontend sabe que orden mirar sin que el token
| tenga que viajar en la URL.
|
| El backend NO manda nada por la URL: /tickets/mp/callback redirige a la raiz
| pelada. Esto es lo que hace que http://entradas.test no se ensucie con query
| ni fragment despues de pagar.
*/
const ORDER_KEY = 'et:pending-order';

export function rememberOrder(order) {
    try {
        sessionStorage.setItem(ORDER_KEY, JSON.stringify({
            order_id: order.order_id ?? null,
            token: order.token ?? order.public_token ?? null,
            event_id: order.event_id ?? null,
            at: Date.now(),
        }));
    } catch {
        // sessionStorage puede estar bloqueado (modo privado estricto). No es
        // motivo para romper el pago: la pantalla de resultado cae al backend
        // para ubicar la orden.
    }
}

export function readPendingOrder() {
    try {
        const raw = sessionStorage.getItem(ORDER_KEY);

        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw);

        return parsed?.order_id ? parsed : null;
    } catch {
        return null;
    }
}

export function forgetOrder(orderId = null) {
    try {
        const current = readPendingOrder();

        // Si se pide borrar una orden puntual y la guardada es otra, no se toca.
        if (orderId && current && current.order_id !== orderId) {
            return;
        }

        sessionStorage.removeItem(ORDER_KEY);
    } catch {
        // sin storage no hay nada que borrar.
    }
}

export const useRoute = computed(() => ({ name: state.name, params: state.params }));