/*
|--------------------------------------------------------------------------
| Cliente HTTP
|--------------------------------------------------------------------------
|
| Un solo lugar donde se habla con el backend. Centralizarlo permite tres
| cosas que si estan en cada pantalla se olvidan:
|
|   1. CSRF: se manda el token en todas las peticiones que no son GET.
|   2. Sesion: si el servidor responde 401, la app vuelve al login sola,
|      sin que cada pantalla tenga que mirar el codigo de error.
|   3. Errores: se traduce el 422 de validacion a algo que se pueda pintar
|      en el formulario sin adivinar la forma de la respuesta.
*/

import axios from 'axios';

const client = axios.create({
    baseURL: '/',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

client.interceptors.request.use((config) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    if (token && config.method && config.method !== 'get') {
        config.headers['X-CSRF-TOKEN'] = token;
    }

    return config;
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

client.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            onUnauthorized();
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
    patch: (url, data) => axios.patch(url, data),
    delete: (url) => client.delete(url),
};

export default api;