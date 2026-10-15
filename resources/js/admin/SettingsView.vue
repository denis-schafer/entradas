<script setup>
/**
 * Configuracion general.
 *
 * Identidad y mensajes del portal. Las credenciales de pago (MercadoPago,
 * Multipago, firma de los QR) se administran en "Medios de pago", no aca.
 *
 * Al final van las herramientas de mantenimiento: modo test y blanqueo de la
 * base de datos.
 */
import { ref, computed, onMounted } from 'vue';
import api, { toError } from '../api.js';
import { toast } from '../ui/toast.js';
import { confirm } from '../ui/confirm.js';
import { appState, fetchAppState } from '../appState.js';

const configs = ref([]);
const drafts = ref({});
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const savingName = ref('');
const uploading = ref('');
const toggling = ref(false);
const resetting = ref(false);

// El nombre de la fila es la clave real del backend, asi que se muestra
// traducido en pantalla pero no se renombra: cambiarlo seria cambiar el
// contrato que lee el portal.
const LABELS = {
    business_name: 'Nombre del negocio',
    portal_logo: 'Logo del portal',
    portal_primary_color: 'Color principal',
    portal_secondary_color: 'Color secundario',
    welcome_message: 'Mensaje de bienvenida',
    success_message: 'Mensaje de pago aprobado',
    rejection_message: 'Mensaje de pago rechazado',
};

const GROUPS = [
    {
        title: 'Identidad',
        hint: 'Como se ve la marca en el portal.',
        names: ['business_name', 'portal_logo', 'portal_primary_color', 'portal_secondary_color'],
    },
    {
        title: 'Mensajes',
        hint: 'Textos que ve el comprador segun como termino el pago.',
        names: ['welcome_message', 'success_message', 'rejection_message'],
    },
];

function byName(name) {
    return configs.value.find((config) => config.name === name);
}

function label(config) {
    return LABELS[config?.name] || config?.name || '';
}

function draft(name) {
    return drafts.value[name] ?? '';
}

function setDraft(name, value) {
    drafts.value = { ...drafts.value, [name]: value };
}

function isImage(config) {
    return config?.type === 'image';
}

function isColor(name) {
    return name.endsWith('_color');
}

const visibleGroups = computed(() => GROUPS.map((group) => ({
    ...group,
    fields: group.names.map(byName).filter(Boolean),
})).filter((group) => group.fields.length));

async function load() {
    try {
        const { data } = await api.get('tickets-admin/config');

        configs.value = data;

        const initial = {};

        data.forEach((config) => {
            initial[config.name] = config.value ?? '';
        });

        drafts.value = initial;
        error.value = '';
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        loading.value = false;
    }
}

async function saveOne(config) {
    savingName.value = config.name;
    saving.value = true;

    try {
        const { data } = await api.post('tickets-admin/config', {
            items: [{ id: config.id, value: drafts.value[config.name] ?? '' }],
        });

        // El servidor responde secrets_ignorados si el lote incluyo un secreto;
        // con un solo campo editable eso no deberia pasar, pero si pasa hay que
        // decirlo en vez de mostrar "guardado".
        if (data.secrets_ignorados?.length) {
            toast('Ese campo no se edita desde aca', 'warning');
        } else {
            toast('Guardado', 'success');
        }

        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        saving.value = false;
        savingName.value = '';
    }
}

async function uploadImage(config, event) {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    uploading.value = config.name;

    try {
        const body = new FormData();

        body.append('file', file);
        body.append('name', config.name);

        const { data } = await api.post('tickets-admin/config/upload', body);

        drafts.value = { ...drafts.value, [config.name]: data.url };

        toast('Imagen subida. GuardÃ¡ para confirmar.', 'success');
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        uploading.value = '';
        event.target.value = '';
    }
}

async function deleteImage(config) {
    try {
        await api.delete('tickets-admin/config/image', { params: { name: config.name } });

        drafts.value = { ...drafts.value, [config.name]: '' };
        toast('Imagen quitada. GuardÃ¡ para confirmar.', 'success');
    } catch (err) {
        toast(toError(err).message, 'danger');
    }
}

/*
| Modo test: al activarlo, todo lo que se cargue desde ahi queda marcado y se
| borra al desactivarlo. Desactivar es la accion destructiva, asi que se pide
| confirmacion.
*/
async function toggleTestMode() {
    const next = !appState.testMode;

    if (!next) {
        const ok = await confirm({
            title: 'Desactivar modo test',
            message: 'Se borraran todos los datos cargados mientras el modo test estuvo activo. Los datos reales no se tocan.',
            confirmLabel: 'Borrar y desactivar',
            tone: 'danger',
        });

        if (!ok) {
            return;
        }
    }

    toggling.value = true;

    try {
        const { data } = await api.post('tickets-admin/app-state/test-mode', { enabled: next });

        appState.testMode = Boolean(data.test_mode);

        toast(data.message || 'Modo test actualizado', 'success');
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        toggling.value = false;
    }
}

/*
| Blanqueo total con doble confirmacion: la segunda es la que deja claro que es
| irreversible. Al terminar se recarga la app, porque la sesion tambien se
| limpia y hay que volver a entrar.
*/
async function resetDatabase() {
    const first = await confirm({
        title: 'Blanquear base de datos',
        message: 'Se borraran TODOS los datos: eventos, ordenes, entradas, escaneos, usuarios y publicidad. Solo quedara el administrador y la configuracion por defecto.',
        confirmLabel: 'Continuar',
        tone: 'danger',
    });

    if (!first) {
        return;
    }

    const second = await confirm({
        title: 'Confirmar blanqueo',
        message: 'Esta accion es irreversible y no se puede deshacer. Queres blanquear la base de datos ahora?',
        confirmLabel: 'Blanquear ahora',
        tone: 'danger',
    });

    if (!second) {
        return;
    }

    resetting.value = true;

    try {
        await api.post('tickets-admin/app-state/reset-database');

        toast('Base de datos blanqueada. Volve a iniciar sesion.', 'success');

        setTimeout(() => window.location.reload(), 1200);
    } catch (err) {
        toast(toError(err).message, 'danger');
        resetting.value = false;
    }
}

onMounted(async () => {
    await load();
    await fetchAppState();
});
</script>

<template>
    <div>
        <header class="mb-3">
            <h2 class="h5 fw-bold mb-0">Configuracion</h2>
            <p class="text-muted-2 small mb-0">Identidad, mensajes y mantenimiento.</p>
        </header>

        <p v-if="error" class="small" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <template v-else>
            <!-- Campos editables -->
            <section v-for="group in visibleGroups" :key="group.title" class="et-surface-raised p-3 mb-3">
                <h3 class="h6 fw-bold mb-0">{{ group.title }}</h3>
                <p class="small text-muted-2 mb-3">{{ group.hint }}</p>

                <div class="row g-3">
                    <div
                        v-for="config in group.fields"
                        :key="config.id"
                        class="col-12"
                        :class="isImage(config) ? 'col-lg-6' : ''"
                    >
                        <template v-if="isImage(config)">
                            <label class="form-label">{{ label(config) }}</label>

                            <div v-if="draft(config.name)" class="mb-2">
                                <img :src="draft(config.name)" :alt="label(config)" class="logo-preview">
                            </div>

                            <div class="d-flex gap-2 flex-wrap">
                                <input
                                    type="file"
                                    class="form-control"
                                    style="max-width: 260px"
                                    accept="image/*"
                                    :disabled="uploading === config.name"
                                    @change="uploadImage(config, $event)"
                                >
                                <button
                                    v-if="draft(config.name)"
                                    class="btn btn-et-ghost btn-sm btn-danger-soft"
                                    @click="deleteImage(config)"
                                >
                                    Quitar
                                </button>
                                <button
                                    class="btn btn-et-ghost btn-sm ms-auto"
                                    :disabled="saving || uploading === config.name"
                                    @click="saveOne(config)"
                                >
                                    <span
                                        v-if="saving && savingName === config.name"
                                        class="spinner-border spinner-border-sm me-1"
                                    ></span>
                                    Guardar
                                </button>
                            </div>
                        </template>

                        <template v-else-if="isColor(config.name)">
                            <label class="form-label" :for="config.name">{{ label(config) }}</label>

                            <div class="d-flex gap-2">
                                <input
                                    :id="config.name"
                                    type="color"
                                    class="form-control form-control-color"
                                    :value="draft(config.name) || '#7C5CFF'"
                                    @input="setDraft(config.name, $event.target.value)"
                                >
                                <input
                                    :value="draft(config.name)"
                                    class="form-control numeric"
                                    maxlength="9"
                                    @input="setDraft(config.name, $event.target.value)"
                                >
                            </div>

                            <button
                                class="btn btn-et-ghost btn-sm mt-2"
                                :disabled="saving"
                                @click="saveOne(config)"
                            >
                                Guardar
                            </button>
                        </template>

<template v-else>
        <label class="form-label" :for="config.name">{{ label(config) }}</label>

        <textarea
            v-if="config.name.endsWith('_message')"
            :id="config.name"
            class="form-control"
            rows="2"
            :value="draft(config.name)"
            @input="setDraft(config.name, $event.target.value)"
        ></textarea>

        <input
            v-else
            :id="config.name"
            :value="draft(config.name)"
            type="text"
            class="form-control"
            @input="setDraft(config.name, $event.target.value)"
        >

        <button
            class="btn btn-et-ghost btn-sm mt-2"
            :disabled="saving"
            @click="saveOne(config)"
        >
            Guardar
        </button>
    </template>
                    </div>
                </div>
            </section>

            <!-- Mantenimiento -->
            <section class="et-surface-raised p-3 mb-3">
                <h3 class="h6 fw-bold mb-0">Mantenimiento</h3>
                <p class="small text-muted-2 mb-3">Herramientas de prueba y limpieza del sistema.</p>

                <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap border rounded p-3 mb-3">
                    <div>
                        <div class="fw-semibold">Modo Test</div>
                        <p class="small text-muted-2 mb-0">
                            Mientras este activo, todo lo que se cargue (eventos, ordenes,
                            entradas, escaneos, usuarios, publicidad) queda marcado y se borra
                            al desactivarlo. El menu se muestra naranja.
                        </p>
                    </div>

                    <div class="form-check form-switch m-0">
                        <input
                            id="test-mode"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            :checked="appState.testMode"
                            :disabled="toggling"
                            @change="toggleTestMode"
                        >
                    </div>
                </div>

                <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap border border-danger rounded p-3">
                    <div>
                        <div class="fw-semibold text-danger">Blanquear Base de Datos</div>
                        <p class="small text-muted-2 mb-0">
                            Borra todos los datos y deja la base como recien instalada: solo
                            queda el administrador y la configuracion por defecto. Esta accion
                            no se puede deshacer.
                        </p>
                    </div>

                    <button
                        class="btn btn-et-danger btn-sm text-nowrap"
                        :disabled="resetting"
                        @click="resetDatabase"
                    >
                        <span v-if="resetting" class="spinner-border spinner-border-sm me-1"></span>
                        Blanquear
                    </button>
                </div>
            </section>
        </template>
    </div>
</template>

<style scoped>
.logo-preview {
    max-width: 200px;
    max-height: 72px;
    object-fit: contain;
    background: var(--et-surface-hover);
    border: 1px solid var(--et-border);
    border-radius: var(--et-radius-sm);
    padding: 0.4rem;
}
</style>