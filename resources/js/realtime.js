/*
|--------------------------------------------------------------------------
| Tiempo real
|--------------------------------------------------------------------------
|
| El websocket acelera, nunca decide. Cada evento trae solo ids y valores
| nuevos; quien lo recibe vuelve a pedir los datos al backend y reemplaza lo
| que tiene. Ese diseno tiene una consecuencia util: si el websocket se cae a
| mitad de una venta, el estado sigue siendo correcto, solo que tarda unos
| segundos mas en verse.
|
| Tres capas, en este orden:
|
|   1. fetch inicial al montar la pantalla.
|   2. eventos websocket.
|   3. polling cada 15 s, que ademas cubre el caso de Reverb apagado.
|
| El polling se pausa con la pestaña oculta y se retoma con pageshow /
| visibilitychange: un portal abierto toda la noche no debería consultar la
| base cada 15 segundos mientras nadie mira.
*/

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { reactive } from 'vue';

const POLL_INTERVAL_MS = 15000;
const BROADCASTING_PATH = '/broadcasting/auth';

let echo = null;
let poller = null;
let pollTask = null;
let connected = false;
let wakeListenersBound = false;

/*
| reactive() y no un objeto plano: el puntito de "en vivo" del pie y el badge de
| las pantallas leen esto en el template. Con un objeto común, Vue no tiene
| forma de saber que cambio y el estado nunca se repinta.
*/
export const realtimeState = reactive({
    connected: false,
    mode: 'polling',
    lastEventAt: null,
});

function reverbConfig() {
    const meta = document.querySelector('meta[name="reverb-key"]')?.content;

    if (!meta) {
        return null;
    }

    try {
        return JSON.parse(atob(meta));
    } catch {
        return null;
    }
}

/**
 * /broadcasting/auth es una ruta web: sin el token de CSRF responde 419 y el
 * suscriptor queda desconectado. Pusher no usa axios, asi que hay que mandarlo
 * a mano desde el meta tag del layout.
 */
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

export function initRealtime() {
    const config = reverbConfig();

    if (!config) {
        // Sin credenciales el websocket simplemente no existe: la app sigue
        // funcionando con polling. No es un error a mostrar.
        return null;
    }

    const options = {
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
        authEndpoint: BROADCASTING_PATH,
        auth: {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
        },
    };

    window.Pusher = Pusher;

    echo = new Echo(options);

    echo.connector.pusher.connection.bind('connected', () => setConnected(true));
    echo.connector.pusher.connection.bind('disconnected', () => setConnected(false));
    echo.connector.pusher.connection.bind('unavailable', () => setConnected(false));
    echo.connector.pusher.connection.bind('failed', () => setConnected(false));

    // Corte: si el socket se cae, Reverb intenta reconectar solo. El polling
    // cubre la ventana en la que no hay conexion, asi que no hay que hacer
    // nada aqui salvo avisar el estado para la UI.
    echo.connector.pusher.connection.bind('state_change', ({ current }) => {
        setConnected(current === 'connected');
    });

    return echo;
}

function setConnected(value) {
    if (connected === value) {
        return;
    }

    connected = value;
    realtimeState.connected = value;
    realtimeState.mode = value ? 'websocket' : 'polling';
}

export function realtime() {
    return echo;
}

/**
 * Se suscribe a un evento de un canal. Devuelve la función para desuscribirse.
 */
export function listen(channel, event, handler) {
    if (!echo) {
        return () => {};
    }

    const target = channel.startsWith('private-') || channel.startsWith('presence-')
        ? echo.private(channel.replace(/^(private|presence)-/, ''))
        : echo.channel(channel);

    const binding = target.listen(`.${event}`, (payload) => {
        realtimeState.lastEventAt = Date.now();
        handler(payload);
    });

    return () => {
        if (typeof binding === 'function') {
            binding();
        }
    };
}

/**
 * Polling de respaldo.
 *
 * Los listeners de pageshow/visibilitychange se registran una sola vez para
 * toda la app. Si se registraran en cada startPolling, cada pantalla que se
 * monta sumaria un par de listeners que nunca se quitan.
 *
 * @param {Function} task  lo que hay que volver a pedir
 * @param {number} intervalMs
 */
export function startPolling(task, intervalMs = POLL_INTERVAL_MS) {
    stopPolling();

    pollTask = task;

    const tick = () => {
        if (document.hidden) {
            return;
        }

        Promise.resolve()
            .then(() => pollTask())
            .catch(() => {
                // Un fallo puntual de polling no debe cortar el ciclo: el
                // siguiente intento reintenta solo.
            });
    };

    poller = setInterval(tick, intervalMs);

    if (!wakeListenersBound) {
        wakeListenersBound = true;

        // BFCache: al volver con la flecha del navegador la pantalla puede tener
        // datos viejos. Se refresca en vez de confiar en lo que quedo en memoria.
        window.addEventListener('pageshow', (e) => {
            if (e.persisted && pollTask) {
                tick();
            }
        });

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && pollTask) {
                tick();
            }
        });
    }

    return stopPolling;
}

export function stopPolling() {
    if (poller) {
        clearInterval(poller);
        poller = null;
    }
}

/**
 * Deja de escuchar de todo lo que se suscribio en un onUnmounted. Cada listen()
 * devuelve su propio unsubscribe, asi que el componente lo llama por su cuenta.
 */
export function destroyRealtime() {
    stopPolling();

    if (echo) {
        echo.disconnect();
        echo = null;
    }

    connected = false;
    realtimeState.connected = false;
    realtimeState.mode = 'polling';
}