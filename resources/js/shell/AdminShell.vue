<script setup>
/**
 * Shell del panel de administracion.
 *
 * Es claro a proposito: el panel se usa de dia, con listas y tablas, y la
 * diferencia de tema con el portal ayuda a que nadie confunda la pantalla de
 * venta con la de operacion.
 *
 * Es un shell de escritorio con barra lateral, pero en pantallas chicas la
 * barra se convierte en un drawer. La idea es que en la puerta (una tablet en
 * la caja) el escaner sea lo unico que se vea.
 */
import { ref, computed, onMounted, onBeforeUnmount, watch, defineAsyncComponent } from 'vue';
import { useRoute, go, defineRoutes } from './router.js';
import { destroyRealtime, realtimeState } from '../realtime.js';
import api from '../api.js';
import DashboardView from '../admin/DashboardView.vue';
import EventsView from '../admin/EventsView.vue';
import EventEditView from '../admin/EventEditView.vue';
import OrdersView from '../admin/OrdersView.vue';
import TicketsView from '../admin/TicketsView.vue';
import ScansView from '../admin/ScansView.vue';
import UsersView from '../admin/UsersView.vue';
import AdsView from '../admin/AdsView.vue';
import SettingsView from '../admin/SettingsView.vue';
import PaymentMethodsView from '../admin/PaymentMethodsView.vue';
import PasswordView from '../admin/PasswordView.vue';

/*
| El escaner y las estadisticas se cargan aparte a proposito: entre ZXing y
| Chart.js pesan mas que el resto de la app junta, y son dos pantallas de las
| que se usan una vez cada tanto. Dejarlas en el bundle inicial hacia que
| cualquiera queiera ver una orden pagara la descarga del lector de QR.
*/
const ScannerView = defineAsyncComponent(() => import('../admin/ScannerView.vue'));
const StatisticsView = defineAsyncComponent(() => import('../admin/StatisticsView.vue'));

const props = defineProps({
    user: { type: Object, required: true },
});

const emit = defineEmits(['logged-out']);

const route = useRoute;
const menuOpen = ref(false);

// El usuario vive en la raiz (Root) para que login y logout pasen por un solo
// lugar; este shell guarda una copia para poder refrescarla desde aca.
const user = ref(props.user);

const VIEWS = {
    dashboard: DashboardView,
    events: EventsView,
    'event-edit': EventEditView,
    orders: OrdersView,
    tickets: TicketsView,
    scanner: ScannerView,
    scans: ScansView,
    users: UsersView,
    ads: AdsView,
    settings: SettingsView,
    'payment-methods': PaymentMethodsView,
    password: PasswordView,
    statistics: StatisticsView,
};

defineRoutes(VIEWS);

const currentView = computed(() => VIEWS[route.value.name] || DashboardView);

/*
| Un solo lugar con la lista de secciones, para que el menu lateral y los
| permisos no se dupliquen. "scanner" va primero a proposito: es lo que se usa
| en la puerta del evento.
*/
const ALL_NAV = [
    { name: 'scanner', label: 'Escanear', icon: 'bi-upc-scan' },
    { name: 'scans', label: 'Escaneos', icon: 'bi-clock-history' },
    { name: 'orders', label: 'Ordenes', icon: 'bi-receipt' },
    { name: 'tickets', label: 'Entradas', icon: 'bi-ticket-perforated' },
    { name: 'events', label: 'Eventos', icon: 'bi-calendar-event' },
    { name: 'statistics', label: 'Estadisticas', icon: 'bi-graph-up' },
    { name: 'users', label: 'Usuarios', icon: 'bi-people' },
    { name: 'ads', label: 'Publicidad', icon: 'bi-megaphone' },
    { name: 'payment-methods', label: 'Medios de pago', icon: 'bi-credit-card' },
    { name: 'settings', label: 'Configuracion', icon: 'bi-gear' },
    { name: 'password', label: 'Mi contrasena', icon: 'bi-key' },
];

/*
| El menu se arma contra la lista de rutas que mando el backend. Es la misma
| que usa User::allowedPanelRoutes() en el middleware, o sea que el menu nunca
| ofrece una seccion que despues el servidor responde con 403. Un admin recibe
| ["*"] y ve todo.
*/
const NAV = computed(() => {
    const allowed = user.value?.routes;

    if (!Array.isArray(allowed) || allowed.includes('*')) {
        return ALL_NAV;
    }

    return ALL_NAV.filter((item) => allowed.includes(item.name));
});

/*
| El panel se usa mucho con el celu en la mano, en la puerta del evento. Por eso
| el item activo del menu se marca aunque la pantalla este en un subformulario
| (event-edit pertenece a "Eventos").
*/
const ACTIVE_FOR = {
    'event-edit': 'events',
};

/*
| Los permisos se resuelven contra la lista que manda el backend, no contra el
| menu. Son dos cosas distintas: "events" es una seccion del menu, mientras que
| "event-edit" es la pantalla de edicion que se abre desde ahi y no tiene entrada
| propia en el menu. Preguntar al menu si "event-edit" esta permitido daba que no,
| y el admin que iba a crear un evento terminaba en Escanear.
*/
function canGo(name) {
    const allowed = user.value?.routes;

    // Sin lista de permisos no hay nada que filtrar: es un admin de los previos
    // a los roles, o una respuesta vieja. Se deja pasar.
    if (!Array.isArray(allowed) || allowed.includes('*')) {
        return true;
    }

    if (allowed.includes(name)) {
        return true;
    }

    // Una pantalla sin entrada propia en el menu hereda el permiso de la
    // seccion desde la que se abre: "event-edit" se abre desde "events".
    return Boolean(ACTIVE_FOR[name]) && allowed.includes(ACTIVE_FOR[name]);
}

const activeNav = computed(() => ACTIVE_FOR[route.value.name] || route.value.name);

function navigate(name) {
    menuOpen.value = false;
    go(name);
}

/*
| Un cajero no tiene dashboard, asi que "ir al primero que puede ver" es
| escanear. Es adonde lo manda la app al entrar y adonde vuelve siempre.
*/
function firstAllowedRoute() {
    return NAV.value[0]?.name || 'password';
}

function ensureAllowed() {
    if (route.value.name === 'loading') {
        return;
    }

    if (!canGo(route.value.name)) {
        go(firstAllowedRoute());
    }
}

function openPortal() {
    /*
    | ?as=portal fuerza el PortalShell en esta pestaña aunque la sesion sea de
    | admin (ver app.js: la raiz lee el param al montar y limpia la URL). Sin
    | esto, las cookies compartidas harian que la nueva pestaña caiga en el
    | mismo AdminShell.
    */
    window.open('/?as=portal', '_blank', 'noopener');
}

async function logout() {
    try {
        await api.post('tickets-admin/auth/logout');
    } catch {
        // Aunque el servidor no responda, la sesion local se cierra igual.
    }

    // Recarga completa a proposito: el logout rota el token de CSRF en el
    // servidor y el meta tag de esta pagina queda viejo. Recargar trae cookies
    // y token frescos y evita el 419 en el proximo inicio de sesion.
    emit('logged-out');
    window.location.reload();
}

onMounted(() => {
    if (route.value.name === 'loading' || !(route.value.name in VIEWS)) {
        go(firstAllowedRoute());

        return;
    }

    // Un cajero podria llegar con la ruta de dashboard en memoria desde una
    // sesion anterior de admin: el servidor la negaria con 403.
    ensureAllowed();
});

/*
| La SPA no tiene router: el unico modo de cambiar de pantalla es go(). Un
| cajero podria llegar a una pantalla prohibida por una accion interna de otro
| componente, asi que cada cambio de ruta se revalida contra los permisos.
*/
watch(() => route.value.name, () => {
    ensureAllowed();
});

onBeforeUnmount(() => {
    destroyRealtime();
});
</script>

<template>
    <div class="admin" :class="{ 'is-drawer-open': menuOpen }">
        <aside class="admin__side no-print">
            <div class="admin__brand">
                <span class="admin__brand-mark"></span>
                <span class="fw-bold">Tickets</span>
            </div>

            <nav class="admin__nav">
                <button
                    v-for="item in NAV"
                    :key="item.name"
                    class="admin__nav-item"
                    :class="{ 'is-on': activeNav === item.name }"
                    @click="navigate(item.name)"
                >
                    <i class="bi" :class="item.icon"></i>
                    <span>{{ item.label }}</span>
                </button>
            </nav>

            <div class="admin__side-foot">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-circle-fill live-dot" :class="{ 'is-live': realtimeState.connected }"></i>
                    <span class="small text-muted-2">
                        {{ realtimeState.connected ? 'En vivo' : 'Sin conexion en vivo' }}
                    </span>
                </div>

                <button class="admin__user" @click="navigate('settings')">
                    <i class="bi bi-person-circle"></i>
                    <span class="text-truncate">{{ user.name }}</span>
                </button>

                <button class="btn btn-et-ghost btn-sm w-100" @click="logout">
                    <i class="bi bi-box-arrow-right me-1"></i>Salir
                </button>
            </div>
        </aside>

        <div class="admin__main">
            <header class="admin__top no-print">
                <button class="admin__burger" aria-label="Menu" @click="menuOpen = !menuOpen">
                    <i class="bi" :class="menuOpen ? 'bi-x-lg' : 'bi-list'"></i>
                </button>

                <h1 class="admin__title">
                    {{ NAV.find((n) => n.name === activeNav)?.label || 'Panel' }}
                </h1>

                <div class="d-flex gap-2">
                    <button
                        class="btn btn-et-ghost btn-sm"
                        title="Ver el portal como lo ve el comprador"
                        @click="openPortal"
                    >
                        <i class="bi bi-box-arrow-up-right"></i>
                    </button>
                </div>
            </header>

            <main class="admin__content">
                <component
                    :is="currentView"
                    :key="`${route.name}:${JSON.stringify(route.params)}`"
                    v-bind="{ ...route.params, user }"
                    @navigate="(name, params) => go(name, params)"
                />
            </main>
        </div>

        <div
            v-if="menuOpen"
            class="admin__scrim no-print"
            @click="menuOpen = false"
        ></div>
    </div>
</template>

<style scoped>
.admin {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    min-height: 100vh;
    background: var(--et-bg);
}

@media (min-width: 992px) {
    .admin {
        grid-template-columns: 248px minmax(0, 1fr);
    }
}

.admin__side {
    position: fixed;
    inset: 0 auto 0 0;
    width: 248px;
    z-index: 40;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 1.1rem 0.9rem;
    background: var(--et-bg-elevated);
    border-right: 1px solid var(--et-border);
    transform: translateX(-100%);
    transition: transform var(--et-transition);
}

.is-drawer-open .admin__side {
    transform: translateX(0);
}

@media (min-width: 992px) {
    .admin__side {
        position: sticky;
        top: 0;
        height: 100vh;
        transform: none;
    }
}

.admin__brand {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0 0.4rem;
    font-size: 1.05rem;
}

.admin__brand-mark {
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--et-primary), var(--et-accent));
}

.admin__nav {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
}

.admin__nav-item {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.55rem 0.65rem;
    border: 0;
    border-radius: var(--et-radius-sm);
    background: none;
    color: var(--et-text-muted);
    font-size: 0.9rem;
    font-weight: 500;
    text-align: left;
    width: 100%;
    transition: background-color var(--et-transition), color var(--et-transition);
}

.admin__nav-item:hover {
    background: var(--et-surface-hover);
    color: var(--et-text);
}

.admin__nav-item.is-on {
    background: var(--et-primary-soft);
    color: var(--et-primary);
}

.admin__side-foot {
    border-top: 1px solid var(--et-border);
    padding-top: 0.85rem;
}

.admin__user {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.4rem 0.5rem;
    margin-bottom: 0.5rem;
    border: 0;
    border-radius: var(--et-radius-sm);
    background: none;
    color: var(--et-text-muted);
    font-size: 0.875rem;
    text-align: left;
}

.admin__user:hover {
    background: var(--et-surface-hover);
}

.live-dot {
    font-size: 0.5rem;
    color: var(--et-text-faint);
}

.live-dot.is-live {
    color: var(--et-success);
}

.admin__main {
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.admin__top {
    position: sticky;
    top: 0;
    z-index: 20;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1.25rem;
    background: rgb(255 255 255 / 88%);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid var(--et-border);
}

.admin__title {
    font-size: 1.05rem;
    font-weight: 700;
    margin: 0;
    flex: 1;
}

.admin__burger {
    background: none;
    border: 0;
    font-size: 1.35rem;
    line-height: 1;
    color: var(--et-text);
    padding: 0.25rem;
}

@media (min-width: 992px) {
    .admin__burger {
        display: none;
    }
}

.admin__content {
    flex: 1;
    padding: 1.25rem;
}

@media (min-width: 992px) {
    .admin__content {
        padding: 1.5rem 1.75rem 3rem;
    }
}

.admin__scrim {
    position: fixed;
    inset: 0;
    z-index: 35;
    background: rgb(0 0 0 / 45%);
}

@media (min-width: 992px) {
    .admin__scrim {
        display: none;
    }
}
</style>