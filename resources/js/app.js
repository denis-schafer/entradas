import 'bootstrap-icons/font/bootstrap-icons.css';

import { createApp, defineComponent, ref, computed, onMounted, nextTick, h } from 'vue';
import api, { setUnauthorizedHandler } from './api.js';
import { initRealtime } from './realtime.js';
import { startSessionWatch } from './sessionWatch.js';
import { go, useRoute, defineRoutes, readPendingOrder, consumeRedirect } from './shell/router.js';
import ToastHost from './ui/ToastHost.vue';
import ConfirmDialog from './ui/ConfirmDialog.vue';
import SessionCountdownModal from './ui/SessionCountdownModal.vue';
import PublicEntryView from './portal/PublicEntryView.vue';
import PortalShell from './shell/PortalShell.vue';
import AdminShell from './shell/AdminShell.vue';

/**
 * Raiz de la app.
 *
 * Toda la app se sirve en http://entradas.test y no hay router de URL: esto
 * consulta la sesion una vez y decide que montar. Tres estados:
 *
 *   sin sesion  -> PortalShell (catalogo). El login/registro son pantallas del
 *                  portal; solo se piden al querer comprar o entrar a la cuenta.
 *   comprador   -> PortalShell
 *   admin       -> AdminShell
 *
 * El login vive dentro del PortalShell (ver PortalShell.vue) para que un
 * visitante pueda ver los eventos sin cuenta. La raiz conserva el handler de
 * sesion porque es quien decide, segun is_admin, si el shell que corresponde es
 * el panel o el portal.
 */
const Root = defineComponent({
    name: 'Root',
    components: { PortalShell, AdminShell, ToastHost, ConfirmDialog, SessionCountdownModal },
    setup() {
        const loading = ref(true);
        const user = ref(null);
        const route = useRoute;

        /*
        | "Ver el portal como comprador" desde el panel abre una nueva pestaña
        | con ?as=portal. La sesion se comparte entre pestañas del mismo dominio,
        | asi que si la raiz decidiera por is_admin, esta pestaña volveria a
        | montar AdminShell. Con este flag se fuerza PortalShell y se limpia
        | de la URL para que el parametro no quede persistente al refrescar.
        |
        | Si no hay sesion, el flag no hace nada: un visitante sin login ya ve
        | PortalShell (el catalogo), asi que forzarlo no cambia nada.
        */
        const forcePortal = ref(
            new URLSearchParams(window.location.search).get('as') === 'portal'
        );

        if (forcePortal.value) {
            history.replaceState(null, '', window.location.pathname);
        }

        /*
        | Link de venta que el vendedor manda por WhatsApp: /e/{public_token}.
        | Se lee del pathname porque la app no tiene router de URL; al abrirlo,
        | el comprador aterriza en la vista publica de esa venta (o en "Mis
        | entradas" si ya tenia sesion).
        */
        const entryToken = ref(entryTokenFromPath());

        // Rutas que existen sin sesion. Los shells registran las suyas al
        // montarse; estas se registran siempre para que go() no las rechace.
        defineRoutes({
            login: {},
            register: {},
            entrada: {},
        });

        async function resolveSession() {
            try {
                const { data } = await api.get('tickets-portal/api/auth/me');

                user.value = data.authenticated ? data.user : null;
            } catch {
                user.value = null;
            }
        }

        function applyTheme() {
            // El tema va con el shell y no con la preferencia del sistema: el
            // portal y el panel son dos productos distintos y mezclarlos en la
            // misma sesion seria confuso. Con ?as=portal se fuerza el portal
            // aunque la sesion sea de admin: el tema acompanana.
            const usePortal = forcePortal.value || !user.value?.is_admin;

            document.documentElement.setAttribute(
                'data-bs-theme',
                usePortal ? 'dark' : 'light'
            );
        }

const isAnonymous = computed(() => user.value === null);

/**
 * De un path de entrada publica (/e/{uuid}) saca el token, o null si el path
 * no se parece a uno. El UUID es la credencial que identifica la venta; fuera
 * de ese patron la ruta no pide esta pantalla.
 */
function entryTokenFromPath() {
    const match = window.location.pathname.match(/^\/e\/([0-9a-fA-F-]{36})$/);

    return match ? match[1] : null;
}

/**
 * El visitante sin sesion ve el portal (catalogo) o la vista publica de una
 * venta. La ruta 'entrada' es la unica que se monta a pantalla completa, fuera
 * del PortalShell, porque es un link suelto de WhatsApp y no lleva navegacion.
 *
 * El token del path adelanta la decision solo mientras la ruta sigue en
 * 'loading' (primer render, antes del go inicial): asi el link no parpadea el
 * portal. Una vez que hay un nombre de ruta, manda la ruta: si el visitante
 * toca "Ingresar a mi cuenta" desde la entrada, tiene que ver el login y no
 * quedarse pegado en la entrada (el token sigue en la barra de direcciones).
 */
const anonView = computed(() => {
    if (route.value.name === 'entrada') {
        return 'entrada';
    }

    if (route.value.name === 'loading' && entryToken.value) {
        return 'entrada';
    }

    return 'portal';
});

        function handleLoggedIn(loggedUser) {
            user.value = loggedUser;
            applyTheme();

            // El shell recien autenticado todavia no esta montado, y es el shell
            // quien registra sus rutas. Un nextTick alcanza para que exista el
            // mapa antes de pedirle una pantalla: sin esto, go('dashboard')
            // explota con "Ruta desconocida" en el primer login.
            nextTick(() => {
                if (loggedUser?.is_admin) {
                    // Entra al panel. La contrasena temporal ya no obliga a
                    // cambiarla: se entra directo y quien quiera cambiarla lo
                    // hace desde "Mi contrasena".
                    //   1. cajero -> escanear, que es lo unico que ve.
                    //   2. admin -> el dashboard de siempre.
                    const routes = Array.isArray(loggedUser.routes) ? loggedUser.routes : ['*'];

                    go(routes.includes('*') ? 'dashboard' : routes[0]);

                    return;
                }

                // Comprador: si venia pagando algo, se muestra el resultado de
                // esa orden en vez del listado. La URL seguia siendo la misma.
                const pending = readPendingOrder();

                if (pending) {
                    go('order-result', pending);

                    return;
                }

                // Si el login vino de tocar "Comprar" sin sesion, se vuelve al
                // evento que estaba mirando para no perder la eleccion.
                const redirect = consumeRedirect();

                go(redirect ? redirect.name : 'events', redirect?.params || {});
            });
        }

        function handleLoggedOut() {
            user.value = null;
            applyTheme();
            go('login');
        }

        function handleNavigate(name) {
            go(name);
        }

        /**
         * El PortalShell reenvia el evento 'logged-in' de sus pantallas. Hay dos
         * casos bien distintos:
         *   - login/registro: el usuario pasa de anonimo a autenticado, y hay que
         *     resolver shell y navegacion (handleLoggedIn).
         *   - "Mi cuenta" cambia el nombre: ya hay sesion, solo se actualiza el
         *     usuario para que el encabezado no muestre el viejo.
         */
        function handlePortalLoggedIn(updated) {
            if (!user.value) {
                handleLoggedIn(updated);

                return;
            }

            user.value = updated;
        }

        /**
         * Cierre de sesion desde el aviso de sesion por vencer. Se llama al
         * endpoint del shell que corresponda y se recarga: el logout rota el
         * token de CSRF en el servidor, asi que el meta tag de esta pagina
         * queda viejo y recargar trae cookies y token frescos.
         */
        async function handleSignOut() {
            const endpoint = user.value?.is_admin
                ? 'tickets-admin/auth/logout'
                : 'tickets-portal/api/auth/logout';

            try {
                await api.post(endpoint);
            } catch {
                // Aunque el servidor no responda, se recarga igual para limpiar
                // el estado local.
            }

            window.location.reload();
        }

        /**
         * Limpia restos de bookmarks viejos que usaban #/mp-return en la URL.
         * La app ya no escribe fragmentos, pero dejarlo una vez no cuesta nada
         * y evita que un usuario con un link guardado vea la barra sucia.
         */
        function scrubLegacyHash() {
            if (window.location.hash) {
                history.replaceState(null, '', window.location.pathname);
            }
        }

        onMounted(async () => {
            initRealtime();

            // El aviso de "sesion por vencer" corre de fondo en toda la app;
            // solo se muestra si queda poco tiempo (ver sessionWatch.js).
            startSessionWatch();

            setUnauthorizedHandler(() => {
                // Un 401 con sesion (expiro) manda al login. Sin sesion se
                // ignora: un visitante anonimo navegando el catalogo puede
                // toparse con algun endpoint que pide cuenta, y eso no debe
                // arrancarlo de la pantalla en la que esta.
                if (user.value) {
                    user.value = null;
                    go('login');
                }
            });

            scrubLegacyHash();

            await resolveSession();

            /*
            | El flag solo aplica con sesion existente ("ver el portal como
            | comprador" desde el panel). Sin sesion no hace nada, y hay que
            | limpiarlo aca: si quedara true, un login de admin montaria el
            | PortalShell y el go('dashboard') posterior tiraria "Ruta
            | desconocida" (dashboard la registra el AdminShell).
            */
            if (!user.value) {
                forcePortal.value = false;
            }

            applyTheme();

            loading.value = false;

            // loading en false hace que el shell correspondiente se monte; su
            // setup es el que define las rutas de esa pantalla, asi que la
            // navegacion tiene que esperar al siguiente tick.
            await nextTick();

            /*
            | Link de venta (/e/{token}): prioridad por sobre el home. Con
            | sesion de comprador, sus entradas ya estan en el portal; sin
            | sesion se monta la vista publica de esa venta (pide el token y
            | muestra los QR sin autenticar); una sesion de panel o el flag
            | "ver como comprador" ignoran el link y siguen a lo suyo.
            */
            if (entryToken.value) {
                if (user.value && !user.value.is_admin) {
                    go('my-tickets');
                } else if (user.value?.is_admin || forcePortal.value) {
                    go(user.value?.is_admin ? 'dashboard' : 'events');
                } else {
                    go('entrada', { token: entryToken.value });
                }

                return;
            }

            // El comprador autenticado puede estar volviendo de MercadoPago: la
            // URL llega pelada y la orden se ubica desde sessionStorage.
            if (user.value && !user.value.is_admin) {
                const pending = readPendingOrder();

                go(pending ? 'order-result' : 'events', pending || {});

                return;
            }

            // Admin con ?as=portal: el shell montado es el PortalShell, que no
            // registra "dashboard" (eso lo hace el AdminShell). Ir al home del
            // portal; sin esto el go() tira "Ruta desconocida" en consola.
            if (user.value?.is_admin && forcePortal.value) {
                go('events');

                return;
            }

            go(user.value?.is_admin ? 'dashboard' : 'events');
        });

        return {
            loading,
            user,
            route,
            isAnonymous,
            anonView,
            entryToken,
            forcePortal,
            handleLoggedIn,
            handleLoggedOut,
            handlePortalLoggedIn,
            handleNavigate,
            handleSignOut,
        };
    },
    render() {
        if (this.loading) {
            return h('div', { class: 'boot' }, [
                h('div', { class: 'boot__mark' }),
                h('p', 'Cargando…'),
            ]);
        }

        /*
        | Sin sesion: el visitante navega el catalogo dentro del PortalShell. El
        | unico caso a pantalla completa es /e/{token}, el link de una venta que
        | no lleva navegacion.
        */
        if (this.isAnonymous) {
            if (this.anonView === 'entrada') {
                return h('div', { class: 'root-anon' }, [
                    h(PublicEntryView, {
                        key: 'entrada',
                        token: this.entryToken || this.route.params.token || '',
                        onNavigate: this.handleNavigate,
                    }),
                    h(ToastHost),
                    h(ConfirmDialog),
                ]);
            }

            return h('div', [
                h(PortalShell, {
                    key: 'portal',
                    user: null,
                    onLoggedOut: this.handleLoggedOut,
                    onLoggedIn: this.handlePortalLoggedIn,
                    onNavigate: this.handleNavigate,
                }),
                h(ToastHost),
                h(ConfirmDialog),
            ]);
        }

        return h('div', [
            this.user.is_admin && !this.forcePortal
                ? h(AdminShell, {
                    key: 'admin',
                    user: this.user,
                    onLoggedOut: this.handleLoggedOut,
                    onNavigate: this.handleNavigate,
                })
                : h(PortalShell, {
                    key: 'portal',
                    user: this.user,
                    onLoggedOut: this.handleLoggedOut,
                    // Mi cuenta puede cambiar el nombre: la raiz guarda el usuario
                    // nuevo para que el shell no vuelva a mostrar el viejo.
                    onLoggedIn: this.handlePortalLoggedIn,
                    onNavigate: this.handleNavigate,
                }),
            h(ToastHost),
            h(ConfirmDialog),
            h(SessionCountdownModal, { onLogout: this.handleSignOut }),
        ]);
    },
});

createApp(Root).mount('#app');