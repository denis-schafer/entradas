<script setup>
/**
 * Usuarios del panel: administradores, cajeros y compradores.
 *
 * Son dos conjuntos de datos distintos con endpoints distintos (users y
 * users/buyers), asi que viven en tabs en vez de una sola tabla: mezclarlos
 * obligaria a inventar columnas que no existen en ninguno de los dos.
 *
 * En la primera tabla conviven administradores y cajeros porque los dos son
 * del panel; lo que los separa es el rol, y es lo unico que el admin elige al
 * dar de alta a alguien.
 */
import { ref, reactive, watch, computed, onMounted } from 'vue';
import api, { toError } from '../api.js';
import EmptyState from '../ui/EmptyState.vue';
import MultiSelectFilter from '../ui/MultiSelectFilter.vue';
import { toast } from '../ui/toast.js';

const props = defineProps({ user: { type: Object, required: true } });

const tab = ref('admins');

const admins = ref([]);
const buyers = ref([]);
const meta = reactive({ admins: page(1, 1, 0), buyers: page(1, 1, 0) });

// Todos los eventos, para el selector de eventos asignados del form. Solo
// lo usa el admin, que ve los eventos sin limite: el cajero no llega aca.
const events = ref([]);

const search = ref('');
const loading = ref(true);
const error = ref('');

const editor = ref(null);
const editorBusy = ref(false);
const editorError = ref('');

const resetResult = ref(null);
const resetBusy = ref(false);

function page(current, last, total) {
    return { current_page: current, last_page: last, total };
}

// Cada tab tiene su paginacion propia, asi que el pager de abajo opera sobre
// la que este visible en lugar de sobre las dos a la vez.
const currentMeta = computed(() => (tab.value === 'admins' ? meta.admins : meta.buyers));

function goToPage(target) {
    if (tab.value === 'admins') {
        loadAdmins(target);
    } else {
        loadBuyers(target);
    }
}

function money(value) {
    return new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
        minimumFractionDigits: 0,
    }).format(Number(value || 0));
}

function formatDate(value) {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleDateString('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
    });
}

function formatDateTime(value) {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString('es-AR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

async function loadAdmins(target = meta.admins.current_page) {
    const { data } = await api.get('tickets-admin/users', {
        search: search.value || undefined,
        page: target,
    });

    admins.value = data.data || [];
    meta.admins = page(data.current_page || 1, data.last_page || 1, data.total || 0);
}

async function loadBuyers(target = meta.buyers.current_page) {
    const { data } = await api.get('tickets-admin/users/buyers', {
        search: search.value || undefined,
        page: target,
    });

    buyers.value = data.data || [];
    meta.buyers = page(data.current_page || 1, data.last_page || 1, data.total || 0);
}

async function load() {
    loading.value = true;

    try {
        if (tab.value === 'admins') {
            await loadAdmins();
        } else {
            await loadBuyers();
        }

        error.value = '';
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

async function loadEvents() {
    try {
        const { data } = await api.get('tickets-admin/events', { all: 1 });
        events.value = data;
    } catch (err) {
        // Sin la lista el form igual abre: simplemente no hay opciones para elegir.
    }
}

function switchTab(next) {
    tab.value = next;
    search.value = '';
    load();
}

function openCreate() {
    resetResult.value = null;
    editorError.value = '';
    loadEvents();
    editor.value = {
        name: '',
        email: '',
        dni: '',
        phone: '',
        password: '',
        role: 'admin',
        enable: true,
        event_ids: [],
        mode: 'create',
    };
}

function openEdit(user) {
    resetResult.value = null;
    editorError.value = '';
    loadEvents();
    editor.value = {
        id: user.id,
        mode: 'edit',
        name: user.name,
        email: user.email,
        dni: user.dni || '',
        phone: user.phone || '',
        password: '',
        password_confirmation: '',
        // Un role null viene de operadores cargados antes de que existiera el
        // rol: para la app son administradores, y por eso se muestran como tal.
        role: user.role === 'cajero' ? 'cajero' : 'admin',
        enable: Boolean(user.enable),
        // Eventos que este cajero puede ver y escanear. El admin no usa esto
        // (ve todos los eventos igual), pero se precarga igual de la fila.
        event_ids: (user.events || []).map((event) => event.id),
    };
}

function closeEditor() {
    editor.value = null;
    editorError.value = '';
}

/*
| Las asignaciones solo limitan a los cajeros: al admin se le manda lista
| vacia para que no queden filas viejas colgando si alguien bajo un rol.
*/
function eventIdsForSubmit() {
    return editor.value.role === 'cajero' ? editor.value.event_ids : [];
}

async function submitEditor() {
    editorBusy.value = true;
    editorError.value = '';

    try {
        if (editor.value.mode === 'create') {
            await api.post('tickets-admin/users', {
                name: editor.value.name,
                email: editor.value.email,
                dni: editor.value.dni || null,
                phone: editor.value.phone || null,
                password: editor.value.password,
                role: editor.value.role,
                enable: editor.value.enable,
                event_ids: eventIdsForSubmit(),
            });

            toast(
                editor.value.role === 'cajero' ? 'Cajero creado' : 'Administrador creado',
                'success',
            );
        } else {
            /*
            | Cambio de password opcional en edicion: si los dos inputs quedaron
            | vacios no se manda nada y la contrasena actual queda intacta. Si
            | el operador escribio algo, se valida que coincidan y se manda al
            | backend, que ademas la marca como temporal (aviso en el listado).
            */
            const passwordFields = {};

            if (editor.value.password) {
                if (editor.value.password !== editor.value.password_confirmation) {
                    throw new Error('Las contrasenas no coinciden.');
                }

                if (editor.value.password.length < 8) {
                    throw new Error('La contrasena debe tener al menos 8 caracteres.');
                }

                passwordFields.password = editor.value.password;
                passwordFields.password_confirmation = editor.value.password_confirmation;
            }

            await api.put(`tickets-admin/users/${editor.value.id}`, {
                name: editor.value.name,
                email: editor.value.email,
                dni: editor.value.dni || null,
                phone: editor.value.phone || null,
                role: editor.value.role,
                enable: editor.value.enable,
                event_ids: eventIdsForSubmit(),
                ...passwordFields,
            });

            toast(
                editor.value.role === 'cajero' ? 'Cajero actualizado' : 'Administrador actualizado',
                'success',
            );
        }

        closeEditor();
        await load();
    } catch (err) {
        const error422 = toError(err);

        editorError.value = error422.message;
    } finally {
        editorBusy.value = false;
    }
}

async function toggleEnable(user) {
    try {
        await api.put(`tickets-admin/users/${user.id}`, { enable: !user.enable });

        toast(user.enable ? 'Usuario reactivado' : 'Usuario desactivado', 'success');
        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    }
}

async function destroy(user) {
    const confirmed = window.confirm(`Eliminar a ${user.name}? No se puede deshacer.`);

    if (!confirmed) {
        return;
    }

    try {
        await api.delete(`tickets-admin/users/${user.id}`);

        toast('Usuario eliminado', 'success');
        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    }
}

async function resetPassword(user) {
    const confirmed = window.confirm(`Resetear la contrasena de ${user.name}?`);

    if (!confirmed) {
        return;
    }

    resetBusy.value = true;
    resetResult.value = null;

    try {
        const { data } = await api.post(`tickets-admin/users/${user.id}/reset-password`);

        resetResult.value = { name: user.name, password: data.new_password };

        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        resetBusy.value = false;
    }
}

function dismissReset() {
    resetResult.value = null;
}

const isSelf = computed(() => (user) => user.id === props.user.id);

let searchTimer = null;

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 350);
});

onMounted(() => {
    load();
    loadEvents();
});
</script>

<template>
    <div>
        <header class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h2 class="h5 fw-bold mb-0">Usuarios</h2>
                <p class="text-muted-2 small mb-0">
                    {{ meta.admins.total }} administradores · {{ meta.buyers.total }} compradores
                </p>
            </div>

            <button v-if="tab === 'admins'" class="btn btn-et-primary btn-sm" @click="openCreate">
                <i class="bi bi-person-plus me-1"></i>Nuevo admin
            </button>
        </header>

        <div class="et-surface-raised p-3 mb-3">
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <div class="tabbar me-auto">
                    <button
                        class="tabbar__item"
                        :class="{ 'is-active': tab === 'admins' }"
                        @click="switchTab('admins')"
                    >
                        Administradores
                    </button>
                    <button
                        class="tabbar__item"
                        :class="{ 'is-active': tab === 'buyers' }"
                        @click="switchTab('buyers')"
                    >
                        Compradores
                    </button>
                </div>

                <input
                    v-model="search"
                    type="search"
                    class="form-control"
                    style="max-width: 280px"
                    placeholder="Nombre, email, DNI o telefono"
                >
            </div>
        </div>

        <p v-if="resetResult" class="et-alert et-alert--success">
            <i class="bi bi-key"></i>
            <span>
                Contrasena nueva de <strong>{{ resetResult.name }}</strong>:
                <code class="numeric">{{ resetResult.password }}</code>
            </span>
            <button class="btn btn-link btn-sm p-0 ms-auto" @click="dismissReset">
                <i class="bi bi-x-lg"></i>
            </button>
        </p>

        <p v-if="error" class="small" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <template v-else-if="tab === 'admins'">
            <EmptyState
                v-if="!admins.length"
                icon="bi-person-gear"
                title="Sin operadores"
                hint="Crea el primer administrador o un cajero para la puerta."
            />

            <div v-else class="et-surface-raised table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>DNI</th>
                            <th>Rol</th>
                            <th>Ultimo acceso</th>
                            <th>Estado</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in admins" :key="row.id">
                            <td>
                                <div class="fw-semibold">
                                    {{ row.name }}
                                    <span v-if="isSelf(row)" class="et-badge et-badge--muted ms-1">Tu usuario</span>
                                </div>
                                <div class="small text-faint numeric">{{ row.phone || '—' }}</div>
                            </td>
                            <td class="small">{{ row.email }}</td>
                            <td class="small numeric">{{ row.dni || '—' }}</td>
                            <td>
                                <span class="et-badge" :class="row.role === 'cajero' ? 'et-badge--muted' : 'et-badge--success'">
                                    {{ row.role === 'cajero' ? 'Cajero' : 'Administrador' }}
                                </span>
                                <div v-if="row.role === 'cajero'" class="small text-faint mt-1">
                                    {{
                                        row.events && row.events.length
                                            ? `Asignado a ${row.events.length} evento${row.events.length === 1 ? '' : 's'}`
                                            : 'Sin eventos asignados'
                                    }}
                                </div>
                            </td>
                            <td class="small text-faint numeric">{{ formatDateTime(row.last_login_at) }}</td>
                            <td>
                                <span v-if="row.enable" class="et-badge et-badge--success">Activo</span>
                                <span v-else class="et-badge et-badge--muted">Inactivo</span>
                                <div v-if="row.must_change_password" class="small text-faint mt-1">
                                    Contrasena temporal
                                </div>
                            </td>
                            <td class="text-end">
                                <div class="btn-row">
                                    <button class="btn btn-et-ghost btn-sm" @click="openEdit(row)">
                                        Editar
                                    </button>
                                    <button
                                        v-if="!isSelf(row)"
                                        class="btn btn-et-ghost btn-sm"
                                        :disabled="!row.enable"
                                        @click="toggleEnable(row)"
                                    >
                                        {{ row.enable ? 'Desactivar' : 'Reactivar' }}
                                    </button>
                                    <button
                                        v-if="!isSelf(row)"
                                        class="btn btn-et-ghost btn-sm btn-danger-soft"
                                        @click="destroy(row)"
                                    >
                                        Eliminar
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <template v-else>
            <EmptyState
                v-if="!buyers.length"
                icon="bi-people"
                title="Sin compradores"
                hint="Los compradores aparecen cuando alguien crea una cuenta en el portal."
            />

            <div v-else class="et-surface-raised table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Comprador</th>
                            <th>DNI</th>
                            <th class="text-end">Ordenes</th>
                            <th class="text-end">Pagadas</th>
                            <th class="text-end">Total comprado</th>
                            <th>Alta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in buyers" :key="row.id">
                            <td>
                                <div class="fw-semibold">{{ row.name }}</div>
                                <div class="small text-faint">{{ row.email }}</div>
                            </td>
                            <td class="small numeric">{{ row.dni || '—' }}</td>
                            <td class="text-end numeric">{{ row.order_count }}</td>
                            <td class="text-end numeric">{{ row.paid_count }}</td>
                            <td class="text-end numeric fw-semibold">{{ money(row.total_spent) }}</td>
                            <td class="small text-faint numeric">{{ formatDate(row.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <nav v-if="currentMeta.last_page > 1" class="pager">
            <button
                class="btn btn-et-ghost btn-sm"
                :disabled="currentMeta.current_page <= 1"
                @click="goToPage(currentMeta.current_page - 1)"
            >
                Anterior
            </button>
            <span class="small text-muted-2 numeric">
                {{ currentMeta.current_page }} / {{ currentMeta.last_page }}
            </span>
            <button
                class="btn btn-et-ghost btn-sm"
                :disabled="currentMeta.current_page >= currentMeta.last_page"
                @click="goToPage(currentMeta.current_page + 1)"
            >
                Siguiente
            </button>
        </nav>

        <!-- Editor -->
        <div v-if="editor" class="modal-backdrop" @click.self="closeEditor">
            <div class="modal-sheet">
                <header class="modal-sheet__head">
                    <h3 class="h6 fw-bold mb-0">
                        {{ editor.mode === 'create' ? 'Nuevo administrador' : 'Editar administrador' }}
                    </h3>
                    <button class="btn btn-link btn-sm p-0" @click="closeEditor">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </header>

                <form class="modal-sheet__body" @submit.prevent="submitEditor">
                    <p v-if="editorError" class="small" style="color: var(--et-danger)">
                        {{ editorError }}
                    </p>

                    <div class="mb-3">
                        <label class="form-label" for="name">Nombre</label>
                        <input id="name" v-model="editor.name" class="form-control" required maxlength="150">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input id="email" v-model="editor.email" type="email" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="dni">DNI</label>
                        <input id="dni" v-model="editor.dni" class="form-control numeric" maxlength="30" placeholder="29923360">
                        <div class="form-text">
                            Opcional. Si lo cargan, este operador puede entrar al panel tipeando DNI en vez de email.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="phone">Telefono</label>
                        <input id="phone" v-model="editor.phone" class="form-control" maxlength="30">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="role">Rol</label>
                        <select id="role" v-model="editor.role" class="form-select">
                            <option value="admin">Administrador — acceso total al panel</option>
                            <option value="cajero">Cajero — solo escanear entradas y dar pulseras</option>
                        </select>
                        <div class="form-text">
                            El cajero ve unicamente Escanear, Escaneos y su contrasena.
                        </div>
                    </div>

                    <div v-if="editor.role === 'cajero'" class="mb-3">
                        <label class="form-label">Eventos asignados</label>
                        <MultiSelectFilter
                            v-model="editor.event_ids"
                            :items="events"
                            placeholder="Buscar evento..."
                            none-text="Ningun evento seleccionado"
                            empty-text="No hay eventos para asignar"
                        />
                        <div class="form-text">
                            Solo escanea los eventos elegidos. Sin ninguno asignado no ve
                            ni puede escanear ninguno hasta que se le asigne.
                        </div>
                    </div>

                    <div v-if="editor.mode === 'create'" class="mb-3">
                        <label class="form-label" for="password">Contrasena</label>
                        <input
                            id="password"
                            v-model="editor.password"
                            type="text"
                            class="form-control"
                            required
                            minlength="8"
                            autocomplete="new-password"
                        >
                        <div class="form-text">Minimo 8 caracteres. Se mostrara en el listado para comunicarsela.</div>
                    </div>

                    <div v-else>
                        <div class="mb-3">
                            <label class="form-label" for="password">Nueva contrasena</label>
                            <input
                                id="password"
                                v-model="editor.password"
                                type="password"
                                class="form-control"
                                minlength="8"
                                autocomplete="new-password"
                                placeholder="Dejar vacia para no cambiar"
                            >
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="password_confirmation">Repetir contrasena</label>
                            <input
                                id="password_confirmation"
                                v-model="editor.password_confirmation"
                                type="password"
                                class="form-control"
                                minlength="8"
                                autocomplete="new-password"
                                placeholder="Solo si completas la anterior"
                            >
                            <div class="form-text">
                                Si dejas los dos vacios la contrasena actual queda como esta.
                                Si completas, tiene que coincidir.
                            </div>
                        </div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input
                            id="enable"
                            v-model="editor.enable"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                        >
                        <label class="form-check-label" for="enable">Usuario activo</label>
                    </div>

                    <footer class="modal-sheet__foot">
                        <button type="button" class="btn btn-et-ghost" @click="closeEditor">Cancelar</button>
                        <button type="submit" class="btn btn-et-primary" :disabled="editorBusy">
                            <span v-if="editorBusy" class="spinner-border spinner-border-sm me-1"></span>
                            Guardar
                        </button>
                    </footer>
                </form>
            </div>
        </div>
    </div>
</template>

<style scoped>
table {
    font-size: 0.875rem;
}

table th {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--et-text-faint);
    font-weight: 700;
}

.btn-row {
    display: flex;
    gap: 0.3rem;
    justify-content: flex-end;
    flex-wrap: wrap;
}

.pager {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    margin-top: 1rem;
}
</style>