/*
|--------------------------------------------------------------------------
| Cliente HTTP
|--------------------------------------------------------------------------
|
| Un solo lugar donde se habla con el backend. Centralizarlo permite tres
| cosas que si estan en cada pantalla se olvidan:
|
|   1. CSRF: el token viaja en la cookie XSRF-TOKEN, que Laravel renueva en
|      cada respuesta (withXSRFToken). NO se copia el meta tag: el login y el
|      logout regeneran el token en el servidor y el meta queda viejo, que era
|      justo el origen del "CSRF token mismatch".
|   2. Sesion: si el servidor responde 401, la app vuelve al login sola,
|      sin que cada pantalla tenga que mirar el codigo de error. Un 419 por
|      sesion vencida se reintenta una vez con cookies frescas.
|   3. Errores: se traduce el 422 de validacion a algo que se pueda pintar
|      en el formulario sin adivinar la forma de la respuesta.
*/

import axios from 'axios';
import { touchSession } from './sessionWatch.js';

const client = axios.create({
    baseURL: '/',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/**
 * Traduce un error de axios a algo con forma estable:
 * { status, message, errors }
 */
export function toError(err) {
    const response = err?.response;

    if (!response) {
        return {
            status: 0,
            message: 'No se pudo conectar con el servidor. Revisa tu conexion.',
            errors: {},
        };
    }

    const data = response.data ?? {};

    return {
        status: response.status,
        message: data.message || data.error || 'Ocurrio un error inesperado.',
        // Laravel devuelve { message, errors: { campo: [mensajes] } }
        errors: typeof data.errors === 'object' && data.errors !== null ? data.errors : {},
        payload: data,
    };
}

export function firstError(formatted) {
    const fields = Object.keys(formatted.errors);

    return fields.length ? formatted.errors[fields[0]][0] : null;
}

/**
 * Errores de sesion. Se despacha por evento global para que el shell remonte
 * la pantalla de login sin que el api client sepa nada de rutas ni de vistas.
 */
let onUnauthorized = () => {};

export function setUnauthorizedHandler(fn) {
    onUnauthorized = fn;
}

/*
 * Recuperacion de 419 (CSRF token mismatch).
 *
 * La cookie XSRF-TOKEN se renueva en cada respuesta, asi que despues de un
 * login deberia alcanzar. Si igual llega un 419 (sesion vencida en la pestaña),
 * se pide "/" una vez para que el servidor emita cookie de sesion y XSRF
 * nuevas, y se reintenta el request original con esas cookies. Si vuelve a
 * fallar, se recarga la pagina una unica vez para no entrar en un loop.
 */
let bootstrapPromise = null;

function bootstrapCsrf() {
    if (!bootstrapPromise) {
        bootstrapPromise = client
            .get('/')
            .catch(() => {})
            .finally(() => {
                bootstrapPromise = null;
            });
    }

    return bootstrapPromise;
}

let reloading = false;

function reloadOnce() {
    if (reloading || sessionStorage.getItem('csrf-reloaded') === '1') {
        return;
    }

    reloading = true;
    sessionStorage.setItem('csrf-reloaded', '1');
    window.location.reload();
}

client.interceptors.response.use(
    (response) => {
        // Una respuesta buena confirma que la sesion sigue viva: se limpia el
        // flag de recarga y se reinicia la cuenta regresiva del aviso.
        sessionStorage.removeItem('csrf-reloaded');
        touchSession();

        return response;
    },
    async (error) => {
        const status = error.response?.status;
        const config = error.config;

        if (error.response) {
            touchSession();
        }

        if (status === 401) {
            onUnauthorized();

            return Promise.reject(error);
        }

        if (status === 419 && config && !config.__csrfRetried) {
            config.__csrfRetried = true;

            await bootstrapCsrf();

            return client.request(config);
        }

        if (status === 419) {
            reloadOnce();
        }

        return Promise.reject(error);
    },
);

/*
 * Helper: serializa un objeto plano como query string. Evita el serializador de
 * axios v1.x, que bajo ciertas configuraciones envuelve la query como
 * `params[event_id]=14` en vez de `event_id=14`.
 */
function buildQuery(params) {
    const parts = [];

    for (const [key, value] of Object.entries(params)) {
        if (value === undefined || value === null) {
            continue;
        }

        parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(value)}`);
    }

    return parts.length ? `?${parts.join('&')}` : '';
}

export const api = {
    get: (url, params) => client.get(`${url}${buildQuery(params || {})}`),
    post: (url, data) => client.post(url, data),
    put: (url, data) => client.put(url, data),
    patch: (url, data) => client.patch(url, data),
    delete: (url) => client.delete(url),
};

export default api;