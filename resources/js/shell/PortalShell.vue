<script setup>
/**
 * Shell del portal de compra.
 *
 * Es oscuro y es el producto que se mira desde el celu: por eso la barra de
 * compra va pegada abajo y las cuentas son de un toque.
 *
 * La navegacion es por estado. Aca se cambia el string de ruta interna y el
 * <component :is> cambia solo, sin que la URL se mueva nunca de
 * http://entradas.test.
 *
 * Este shell solo se monta con sesion de comprador: el login vive en la raiz
 * (ver app.js). Por eso no hay redireccion a login ni guardas de sesion aca.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, go, defineRoutes } from './router.js';
import { destroyRealtime, realtimeState } from '../realtime.js';
import api from '../api.js';
import EventListView from '../portal/EventListView.vue';
import EventDetailView from '../portal/EventDetailView.vue';
import OrderResultView from '../portal/OrderResultView.vue';
import MyTicketsView from '../portal/MyTicketsView.vue';
import MyOrdersView from '../portal/MyOrdersView.vue';
import ProfileView from '../portal/ProfileView.vue';

defineProps({
    user: { type: Object, required: true },
});

const emit = defineEmits(['logged-out', 'logged-in']);

const config = ref({
    business_name: 'Entradas',
    portal_logo: '',
    welcome_message: '',
    terms_url: '',
    privacy_url: '',
});

const menuOpen = ref(false);
const route = useRoute;

/*
| El mapa es lo unico que decide que pantalla se monta. Cada vista se importa
| arriba y se registra aca: agregar una pantalla es agregar una linea, y
| defineRoutes() hace que un nombre mal escrito falle de inmediato en vez de
| dejar la pantalla en blanco.
*/
const VIEWS = {
    events: EventListView,
    event: EventDetailView,
    'order-result': OrderResultView,
    'my-tickets': MyTicketsView,
    'my-orders': MyOrdersView,
    profile: ProfileView,
};

defineRoutes(VIEWS);

const currentView = computed(() => VIEWS[route.value.name] || EventListView);

async function loadConfig() {
    try {
        const { data } = await api.get('tickets-portal/api/config');

        config.value = { ...config.value, ...data };

        if (data.business_name) {
            document.title = data.business_name;
        }
    } catch {
        // La identidad tiene valores por defecto: un fallo aca no puede dejar
        // el portal sin pintar.
    }
}

async function logout() {
    try {
        await api.post('tickets-portal/api/auth/logout');
    } catch {
        // Aunque el servidor no responda, la sesion local se cierra igual.
    }

    // Recarga completa a proposito: el logout rota el token de CSRF en el
    // servidor y el meta tag de esta pagina queda viejo. Recargar trae cookies
    // y token frescos y evita el 419 en el proximo inicio de sesion.
    emit('logged-out');
    window.location.reload();
}

onMounted(async () => {
    if (route.value.name === 'loading' || !(route.value.name in VIEWS)) {
        go('events');
    }

    await loadConfig();
});

onBeforeUnmount(() => {
    destroyRealtime();
});
</script>

<template>
    <div class="portal">
        <header class="portal__nav no-print">
            <div class="portal__nav-inner">
                <button class="portal__brand" @click="go('events')">
                    <img v-if="config.portal_logo" :src="config.portal_logo" :alt="config.business_name" height="30">
                    <template v-else>
                        <span class="portal__brand-mark"></span>
                        <span class="fw-bold">{{ config.business_name || 'Entradas' }}</span>
                    </template>
                </button>

                <nav class="portal__links">
                    <button class="portal__link" :class="{ 'is-on': route.name === 'events' }" @click="go('events')">
                        Eventos
                    </button>
                    <button
                        class="portal__link"
                        :class="{ 'is-on': ['my-tickets', 'order-result'].includes(route.name) }"
                        @click="go('my-tickets')"
                    >
                        Mis entradas
                    </button>
                    <button
                        class="portal__link"
                        :class="{ 'is-on': route.name === 'my-orders' }"
                        @click="go('my-orders')"
                    >
                        Mis compras
                    </button>
                </nav>

                <div class="portal__actions">
                    <button class="btn btn-et-ghost btn-sm" @click="go('profile')">
                        <i class="bi bi-person-circle me-1"></i>
                        <span class="d-none d-sm-inline">{{ user.name.split(' ')[0] }}</span>
                    </button>

                    <button class="btn btn-et-ghost btn-sm" title="Cerrar sesion" @click="logout">
                        <i class="bi bi-box-arrow-right"></i>
                    </button>

                    <button class="portal__burger" aria-label="Menu" @click="menuOpen = !menuOpen">
                        <i class="bi" :class="menuOpen ? 'bi-x-lg' : 'bi-list'"></i>
                    </button>
                </div>
            </div>

            <div v-if="menuOpen" class="portal__mobile">
                <button class="portal__link" @click="go('events')">Eventos</button>
                <button class="portal__link" @click="go('my-tickets')">Mis entradas</button>
                <button class="portal__link" @click="go('my-orders')">Mis compras</button>
                <button class="portal__link" @click="go('profile')">Mi cuenta</button>
                <button class="portal__link portal__link--danger" @click="logout">
                    <i class="bi bi-box-arrow-right me-1"></i>Cerrar sesión
                </button>
            </div>
        </header>

        <p v-if="config.welcome_message" class="portal__welcome">
            {{ config.welcome_message }}
        </p>

        <main class="portal__main">
            <component
                :is="currentView"
                :key="`${route.name}:${JSON.stringify(route.params)}`"
                v-bind="{ ...route.params, user, config }"
                @navigate="(name, params) => go(name, params)"
                @logged-in="(updated) => emit('logged-in', updated)"
            />
        </main>

        <footer class="portal__footer no-print">
            <div class="portal__footer-inner">
                <span class="text-faint small">
                    <span class="live-dot" :class="{ 'is-live': realtimeState.connected }"></span>
                    {{ config.business_name }}
                </span>

                <span class="d-flex gap-3 small">
                    <a v-if="config.terms_url" :href="config.terms_url" target="_blank" rel="noopener" class="link-muted-2">
                        Terminos
                    </a>
                    <a v-if="config.privacy_url" :href="config.privacy_url" target="_blank" rel="noopener" class="link-muted-2">
                        Privacidad
                    </a>
                </span>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.portal {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* Dos luces suaves de fondo: dan profundidad sin sumar elementos al DOM. */
.portal::before {
    content: '';
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    background:
        radial-gradient(60rem 40rem at 12% -8%, rgb(124 92 255 / 18%), transparent 60%),
        radial-gradient(48rem 34rem at 92% 4%, rgb(255 61 113 / 12%), transparent 62%);
}

.portal > * {
    position: relative;
    z-index: 1;
}

.portal__nav {
    position: sticky;
    top: 0;
    z-index: 30;
    backdrop-filter: blur(14px);
    background: rgb(10 10 15 / 78%);
    border-bottom: 1px solid var(--et-border);
}

.portal__nav-inner,
.portal__footer-inner {
    max-width: var(--et-shell-max);
    margin: 0 auto;
    padding: 0.75rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 1.25rem;
}

.portal__brand {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    background: none;
    border: 0;
    color: var(--et-text);
    font-size: 1.05rem;
    padding: 0;
}

.portal__brand-mark {
    width: 26px;
    height: 26px;
    border-radius: 9px;
    background: linear-gradient(135deg, var(--et-primary), var(--et-accent));
}

.portal__links {
    display: flex;
    gap: 0.35rem;
}

.portal__link {
    background: none;
    border: 0;
    padding: 0.45rem 0.7rem;
    border-radius: var(--et-radius-sm);
    color: var(--et-text-muted);
    font-size: 0.9rem;
    font-weight: 500;
    transition: color var(--et-transition), background-color var(--et-transition);
}

.portal__link:hover {
    color: var(--et-text);
    background: var(--et-surface-hover);
}

.portal__link--danger {
    color: var(--et-danger, #ff4d6d);
}

.portal__link--danger:hover {
    color: var(--et-danger, #ff4d6d);
    background: rgba(255, 77, 109, 0.1);
}

.portal__link.is-on {
    color: var(--et-primary);
}

.portal__actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    /*
    | Empuja los actions al borde derecho aunque los links esten colapsados en
    | mobile. Sin esto el burger queda pegado al brand y se confunde con el
    | menu principal.
    */
    margin-left: auto;
}

.portal__burger {
    display: none;
    background: none;
    border: 0;
    color: var(--et-text);
    font-size: 1.35rem;
    line-height: 1;
    padding: 0.25rem;
}

.portal__mobile {
    display: none;
    flex-direction: column;
    align-items: flex-end;
    padding: 0.5rem 1.25rem 1rem;
    border-top: 1px solid var(--et-border);
    text-align: right;
}

.portal__welcome {
    max-width: var(--et-shell-max);
    margin: 1rem auto 0;
    padding: 0 1.25rem;
    color: var(--et-text-muted);
    font-size: 0.9rem;
}

.portal__main {
    flex: 1;
    width: 100%;
    max-width: var(--et-shell-max);
    margin: 0 auto;
    padding: 1.75rem 1.25rem 3rem;
}

.portal__footer {
    border-top: 1px solid var(--et-border);
    background: rgb(10 10 15 / 60%);
}

.portal__footer-inner {
    justify-content: space-between;
}

.live-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--et-text-faint);
    margin-right: 0.4rem;
}

.live-dot.is-live {
    background: var(--et-success);
}

@media (max-width: 767.98px) {
    .portal__links,
    .portal__actions .btn {
        display: none;
    }

    .portal__burger {
        display: block;
    }

    .portal__mobile {
        display: flex;
    }
}
</style>