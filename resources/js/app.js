import 'bootstrap-icons/font/bootstrap-icons.css';

import { createApp, defineComponent, ref, computed, onMounted, nextTick, h } from 'vue';
import api, { setUnauthorizedHandler } from './api.js';
import { initRealtime } from './realtime.js';
import { startSessionWatch } from './sessionWatch.js';
import { go, useRoute, defineRoutes, readPendingOrder } from './shell/router.js';
import ToastHost from './ui/ToastHost.vue';
import SessionCountdownModal from './ui/SessionCountdownModal.vue';
import AuthScreen from './ui/AuthScreen.vue';
import RegisterView from './portal/RegisterView.vue';
import PortalShell from './shell/PortalShell.vue';
import AdminShell from './shell/AdminShell.vue';

/**
 * Raiz de la app.
 *
 * Toda la app se sirve en http://entradas.test y no hay router de URL: esto
 * consulta la sesion una vez y decide que montar. Tres estados:
 *
 *   sin sesion  -> un unico login (AuthScreen)
 *   comprador   -> PortalShell
 *   admin       -> AdminShell
 *
 * El login esta aca y no dentro de los shells a proposito: es UNA pantalla, sin
 * selector de modo. El backend acepta email o DNI en el mismo campo y devuelve
 * is_admin; la raiz monta el panel o el portal segun eso, no segun lo que el
 * usuario haya elegido en un formulario.
 */
const Root = defineComponent({
    name: 'Root',
    components: { PortalShell, AdminShell, ToastHost, SessionCountdownModal, AuthScreen, RegisterView },
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
        | Si no hay sesion, el flag no hace nada: lo que ve un visitante sin
        | login es la pantalla de login, no el portal.
        */
        const forcePortal = ref(
            new URLSearchParams(window.location.search).get('as') === 'portal'
        );

        if (forcePortal.value) {
            history.replaceState(null, '', window.location.pathname);
        }

        // Rutas que existen sin sesion. Los shells registran las suyas al
        // montarse; estas se registran siempre para que go() no las rechace.
        defineRoutes({
            login: {},
            register: {},
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

        const anonView = computed(() => {
            if (route.value.name === 'register') {
                return 'register';
            }

            return 'auth';
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

                go(pending ? 'order-result' : 'events', pending || {});
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
                user.value = null;
                go('login');
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

            go(user.value?.is_admin ? 'dashboard' : 'login');
        });

        return {
            loading,
            user,
            route,
            isAnonymous,
            anonView,
            forcePortal,
            handleLoggedIn,
            handleLoggedOut,
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

        if (this.isAnonymous) {
            return h('div', { class: 'root-anon' }, [
                this.anonView === 'register'
                    ? h(RegisterView, {
                        key: 'register',
                        onLoggedIn: this.handleLoggedIn,
                        onNavigate: this.handleNavigate,
                    })
                    : h(AuthScreen, {
                        key: 'auth',
                        onLoggedIn: this.handleLoggedIn,
                        onNavigate: this.handleNavigate,
                    }),
                h(ToastHost),
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
                    onLoggedIn: (updated) => {
                        this.user = updated;
                    },
                    onNavigate: this.handleNavigate,
                }),
            h(ToastHost),
            h(SessionCountdownModal, { onLogout: this.handleSignOut }),
        ]);
    },
});

createApp(Root).mount('#app');