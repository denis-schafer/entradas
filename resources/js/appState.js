import { reactive } from 'vue';
import api from './api.js';

/**
 * Estado global de la aplicacion, compartido entre pantallas.
 *
 * Hoy solo expone el "Modo Test": cuando esta activo el menu del panel se
 * pinta naranja y muestra la etiqueta. Vive en un modulo reactivo (igual que
 * toast.js) para que el shell y la pantalla de Configuracion miren lo mismo
 * sin pasarse props.
 */
export const appState = reactive({
    testMode: false,
});

export async function fetchAppState() {
    try {
        const { data } = await api.get('tickets-admin/app-state');

        appState.testMode = Boolean(data.test_mode);
    } catch {
        // Sin respuesta no se puede afirmar nada: se asume modo normal.
        appState.testMode = false;
    }

    return appState.testMode;
}
