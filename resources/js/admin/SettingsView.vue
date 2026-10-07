<script setup>
/**
 * Configuracion general.
 *
 * Se muestran como secretos los que nunca se devuelven en claro al navegador.
 * El access_token de MP es por evento y se gestiona en la edicion de cada uno;
 * aca solo queda mp_webhook_secret (global, opcional) y qr_secret (autogenerado).
 */
import { ref, computed, onMounted } from 'vue';
import api, { toError } from '../api.js';
import { toast } from '../ui/toast.js';

const configs = ref([]);
const drafts = ref({});
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const savingName = ref('');
const uploading = ref('');

const READ_ONLY_SECRETS = ['qr_secret'];

/*
 * Secrets que se pueden escribir desde el panel pero NO se devuelven en claro
 * al listar (el backend los enmascara como "__set__"). Se inicializan vacios en
 * drafts para que el input arranque limpio, no con el valor "__set__".
 */
const EDITABLE_SECRET_NAMES = ['mp_webhook_secret'];

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
    terms_url: 'URL de terminos',
    privacy_url: 'URL de privacidad',
    redirect_uri: 'URL de retorno de MercadoPago',
    mp_webhook_secret: 'Secreto del webhook',
    qr_secret: 'Firma de los QR',
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
    {
        title: 'Enlaces y pagos',
        hint: 'Terminos, privacidad y la URL de retorno de MercadoPago.',
        names: ['terms_url', 'privacy_url', 'redirect_uri'],
    },
];

function byName(name) {
    return configs.value.find((config) => config.name === name);
}

function label(config) {
    return LABELS[config?.name] || config?.name || '';
}

/**
 * Un secreto cargado llega como "__set__" porque el backend nunca devuelve el
 * valor. O sea que "__set__" ES la señal de "esta configurado"; tomarlo como
 * "sin configurar" hacia que mpConnected() diera false con la cuenta ya
 * conectada y que el panel mostrara "Sin configurar" en secretos reales.
 */
function isSet(config) {
    if (!config) {
        return false;
    }

    return config.value === '__set__' || Boolean(config.value);
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

const PLACEHOLDERS = {
    redirect_uri: 'https://tickets.salfest.com.ar',
    terms_url: 'https://...',
    privacy_url: 'https://...',
};

function placeholderFor(name) {
    return PLACEHOLDERS[name] ?? '';
}

const visibleGroups = computed(() => GROUPS.map((group) => ({
    ...group,
    fields: group.names.map(byName).filter(Boolean),
})).filter((group) => group.fields.length));

const secretRows = computed(() => READ_ONLY_SECRETS.map((name) => byName(name)).filter(Boolean));

async function load() {
    try {
        const { data } = await api.get('tickets-admin/config');

        configs.value = data;

        const initial = {};

        data.forEach((config) => {
            if (READ_ONLY_SECRETS.includes(config.name)) {
                // qr_secret: ni se muestra ni se edita.
                return;
            }

            if (EDITABLE_SECRET_NAMES.includes(config.name)) {
                // El backend enmascara el valor: inicializamos vacio para que
                // el input arranque limpio. Si ya hay un valor guardado, el
                // placeholder lo indica.
                initial[config.name] = '';

                return;
            }

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

/**
 * Edita un secreto escribible (mp_access_token, mp_webhook_secret). A diferencia
 * de saveOne(), limpia el draft despues de guardar: el valor NUNCA queda visible
 * en la UI (el backend lo enmascara como "__set__" en el GET siguiente).
 *
 * Manda {name, value} y NO {id, value}: la fila puede no existir todavia (el
 * seeder original no siembra mp_access_token / mp_webhook_secret), y el backend
 * hace upsert por name. Mando id tambien si esta, asi un save sobre una fila
 * existente sigue funcionando aunque el frontend haya quedado con un id viejo.
 */
async function saveSecret(name) {
    const config = byName(name);

    const value = drafts.value[name] ?? '';

    if (!value || !value.trim()) {
        toast('Pega un valor antes de guardar', 'warning');

        return;
    }

    saving.value = true;
    savingName.value = name;

    try {
        const item = { name, value: value.trim() };

        if (config?.id) {
            item.id = config.id;
        }

        await api.post('tickets-admin/config', {
            items: [item],
        });

        // Limpiar el draft para que no quede el token pegado en memoria
        // despues de guardar. El backend persiste el valor, el listado siguiente
        // lo muestra como "Configurado" via isSet().
        setDraft(name, '');

        toast('Guardado', 'success');
        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        saving.value = false;
        savingName.value = '';
    }
}

async function clearSecret(name) {
    if (!confirm('Quitar el valor guardado?')) {
        return;
    }

    const config = byName(name);

    saving.value = true;

    try {
        const item = { name, value: '' };

        if (config?.id) {
            item.id = config.id;
        }

        await api.post('tickets-admin/config', {
            items: [item],
        });

        toast('Quitado', 'success');
        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        saving.value = false;
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

onMounted(load);
</script>

<template>
    <div>
        <header class="mb-3">
            <h2 class="h5 fw-bold mb-0">Configuracion</h2>
            <p class="text-muted-2 small mb-0">Identidad, mensajes y conexion de pagos.</p>
        </header>

        <p v-if="error" class="small" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <template v-else>
            <!-- MercadoPago: configuracion de plataforma -->
            <section class="et-surface-raised p-3 mb-3">
                <div class="mb-3">
                    <h3 class="h6 fw-bold mb-1">MercadoPago (plataforma)</h3>
                    <p class="small text-muted-2 mb-0">
                        Las credenciales OAuth de la app (Client ID/Secret) van en el
                        <code>.env</code> del servidor, NO aca. El <strong>access_token</strong>
                        de cada organizador se carga en la edicion de su evento (boton
                        "Conectar con MP" o paste manual). Aca queda solo el secreto
                        del webhook de plataforma (opcional).
                    </p>
                </div>

                <!--
                    Secreto del webhook: opcional. Si esta vacio el backend acepta el webhook
                    sin chequear firma. Cargalo aca si definiste uno en el panel de MP.
                -->
                <div class="mb-3">
                    <label class="form-label" for="mp-webhook-secret">Secreto del webhook (opcional)</label>
                    <div class="d-flex gap-2 flex-wrap">
                        <input
                            id="mp-webhook-secret"
                            v-model="drafts.mp_webhook_secret"
                            type="text"
                            class="form-control form-control-sm flex-grow-1"
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="isSet(byName('mp_webhook_secret')) ? 'Configurado. Pegá uno nuevo para reemplazar.' : 'Sin configurar'"
                        >
                        <button
                            class="btn btn-et-ghost btn-sm"
                            :disabled="saving || !drafts.mp_webhook_secret"
                            @click="saveSecret('mp_webhook_secret')"
                        >
                            <span
                                v-if="saving && savingName === 'mp_webhook_secret'"
                                class="spinner-border spinner-border-sm me-1"
                            ></span>
                            Guardar secreto
                        </button>
                    </div>
                </div>

                <hr class="et-divider my-3">

                <div class="row g-2">
                    <div v-for="config in secretRows" :key="config.id" class="col-12 col-md-4">
                        <div class="secret">
                            <div class="small text-faint">{{ label(config) }}</div>
                            <div class="small">
                                <i
                                    class="bi me-1"
                                    :class="isSet(config) ? 'bi-check-circle-fill text-success' : 'bi-dash-circle'"
                                ></i>
                                {{ isSet(config) ? 'Configurado' : 'Sin configurar' }}
                            </div>
                        </div>
                    </div>
                </div>

                <p class="form-text mt-2 mb-0">
                    qr_secret se genera solo en el primer QR firmado, asi que puede figurar
                    "sin configurar" hasta que exista una orden pagada.
                </p>
            </section>

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
            :placeholder="placeholderFor(config.name)"
            @input="setDraft(config.name, $event.target.value)"
        >

        <p
            v-if="config.name === 'redirect_uri'"
            class="form-text mb-0"
        >
            MercadoPago usa esta URL como base para las callbacks de retorno y del webhook.
            Sin HTTPS publico, los pagos no se pueden completar. En
            <code>http://entradas.test</code> no funciona: usa ngrok o similar para
            exponer el sitio local, y pegá aca la direccion HTTPS del tunel.
        </p>

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
        </template>
    </div>
</template>

<style scoped>
.secret {
    padding: 0.5rem 0.7rem;
    border: 1px solid var(--et-border);
    border-radius: var(--et-radius-sm);
    background: var(--et-surface);
}

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