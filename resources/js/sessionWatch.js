/*
|--------------------------------------------------------------------------
| Vigilancia de la sesion
|--------------------------------------------------------------------------
|
| La sesion de Laravel se desliza con cada request: la cookie se renueva con la
| ventana completa cada vez que el navegador habla con el servidor. El problema
| es que si el usuario deja la pestaña quieta, el servidor vence la sesion igual
| y el siguiente POST responde 419/401 en mitad de un formulario.
|
| Aca se guarda la ultima actividad local. Cada respuesta de la API (ver api.js)
| la actualiza, y un contador avisa cuando queda poco para el vencimiento real
| para que el usuario pueda renovar antes de perder el trabajo.
*/
import { ref } from 'vue';

// Aviso cuando falten diez minutos o menos para el vencimiento.
const WARNING_SECONDS = 10 * 60;
const TICK_MS = 1000;

// Minutos de vida que declara el servidor en el meta tag. Si falta, se usa el
// default de config/session.php para no dejar el aviso desactivado.
const lifetimeMinutes = (() => {
    const raw = document
        .querySelector('meta[name="session-lifetime"]')
        ?.content;
    const parsed = parseInt(raw, 10);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 1440;
})();

let lastActivity = Date.now();

// { warning: boolean, remaining: segundos }
export const sessionState = ref({
    warning: false,
    remaining: lifetimeMinutes * 60,
});

export function touchSession() {
    lastActivity = Date.now();
}

export function secondsRemaining() {
    return Math.max(
        0,
        lifetimeMinutes * 60 - Math.floor((Date.now() - lastActivity) / 1000),
    );
}

export function startSessionWatch() {
    const evaluate = () => {
        const remaining = secondsRemaining();

        sessionState.value = {
            warning: remaining > 0 && remaining <= WARNING_SECONDS,
            remaining,
        };
    };

    evaluate();

    const timer = setInterval(evaluate, TICK_MS);

    return () => clearInterval(timer);
}
