<script setup>
/**
 * "Medios de pago": credenciales de cada proveedor y su asignacion por evento.
 *
 * Dos pisos:
 *   - Config GLOBAL del proveedor (la cuenta): enabled + credenciales.
 *   - Por evento: enabled y valores particulares (token MP del evento, webhook
 *     de Multipago).
 *
 * Las credenciales viajan enmascaradas: el backend devuelve '••••••••' donde
 * hay un secreto guardado y, al guardar, ese valor literal se interpreta como
 * "no tocar". Para borrar un secreto esta el link "Quitar" de cada campo.
 */
import { ref, onMounted } from 'vue';
import api, { toError } from '../api.js';
import { toast } from '../ui/toast.js';

const props = defineProps({
    id: { type: [Number, String], default: null },
});

const loading = ref(false);
const methods = ref([]);

const open = ref({});

const FIELDS = {
    mercadopago: {
        global: [
            { key: 'client_id', label: 'Client ID (OAuth)', secret: false },
            { key: 'client_secret', label: 'Client Secret (OAuth)', secret: true },
            { key: 'platform_access_token', label: 'Access token de la plataforma', secret: true },
        ],
        eventTitle: 'Cuenta por evento',
        eventHint: 'Cada evento puede cobrar con su propia cuenta de MP. Conectala con el boton de OAuth o pega el Access token.',
    },
    multipago: {
        global: [
            { key: 'bersacode', label: 'Bersa code (comercio)', secret: false },
            { key: 'username', label: 'Usuario API', secret: false },
            { key: 'password', label: 'Contraseña API', secret: true },
        ],
        eventTitle: 'Webhook por evento',
        eventHint: 'Multipago avisa de cada pago por una URL unica por evento. Copiala en la configuracion de Multipago.',
    },
};

// Borradores de la config global y por evento (lo que se edita en pantalla).
const globalDrafts = ref({});
const eventDrafts = ref({});
// "" = sin token remoto; value = '••••••••' (hay secreto guardado pero no se muestra).
const storedEvent = ref({});
const clearedSecrets = ref({});
const saving = ref({});
const savingEvent = ref({});
const testing = ref({});
const validating = ref(false);
const validateFrom = ref('');
const validateTo = ref('');
const webhookUrls = ref({});
const copied = ref('');

async function load() {
    loading.value = true;

    try {
        const { data } = await api.get('tickets-admin/payment-methods');

        methods.value = data.methods || [];

        methods.value.forEach((m) => {
            const fields = FIELDS[m.code]?.global || [];
            const drafts = {};
            const stored = {};

            fields.forEach((f) => {
                const value = m.config?.[f.key] ?? '';

                drafts[f.key] = f.secret && value ? '' : value;
                stored[f.key] = value;
            });

            globalDrafts.value[m.code] = drafts;
            storedEvent.value[m.code] = (m.events || []).reduce((acc, ev) => {
                acc[ev.event_id] = ev.config?.access_token || '';
                return acc;
            }, {});
            eventDrafts.value[m.code] = (m.events || []).reduce((acc, ev) => ({
                ...acc,
                [ev.event_id]: ev.config?.access_token || '',
            }), {});
            clearedSecrets.value[m.code] = [];
            open.value[m.code] = open.value[m.code] ?? false;
        });
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        loading.value = false;
    }
}

function toggleOpen(code) {
    open.value[code] = !open.value[code];
}

function methodProps(code) {
    return methods.value.find((m) => m.code === code) || {};
}

function hasSecret(method, key) {
    const stored = method.config?.[key];
    return typeof stored === 'string' && stored.startsWith('•');
}

/* Valor guardado (enmascarado) del token por evento. */
function eventStored(event, code) {
    return storedEvent.value[code]?.[event.event_id] || '';
}

function eventHasToken(event, code) {
    return typeof eventStored(event, code) === 'string' && eventStored(event, code).startsWith('•');
}

async function saveGlobal(code) {
    const method = methodProps(code);
    const drafts = globalDrafts.value[code];
    const cleared = clearedSecrets.value[code] || [];

    saving.value[code] = true;

    try {
        await api.put(`tickets-admin/payment-methods/${code}`, {
            enabled: globalDrafts.value.enabled?.[code] ?? method.enabled,
            config: drafts,
            cleared_keys: cleared,
        });

        toast('Config guardada', 'success');
        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        saving.value[code] = false;
    }
}

function clearSecret(code, key, target) {
    if (!clearedSecrets.value[code]) {
        clearedSecrets.value[code] = [];
    }

    clearedSecrets.value[code].push(key);
    target[key] = '';
}

async function saveEvent(code, eventId) {
    const key = `${code}:${eventId}`;
    const cleared = clearedSecrets.value[code]?.filter((k) => k.startsWith(`ev:${eventId}:`))
        .map((k) => k.replace(`ev:${eventId}:`, '')) || [];

    savingEvent.value[key] = true;

    try {
        await api.put(`tickets-admin/payment-methods/${code}/events/${eventId}`, {
            enabled: globalDrafts.value.eventEna?.[key] ?? (methodProps(code).events.find((e) => e.event_id === eventId)?.enabled ?? true),
            config: { access_token: eventDrafts.value[code]?.[eventId] || '' },
            cleared_keys: cleared,
        });

        toast('Config del evento guardada', 'success');

        if (code === 'multipago') {
            await refreshWebhookUrl(eventId);
        }

        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        savingEvent.value[key] = false;
    }
}

function toggleEvent(code, eventId, value) {
    if (!globalDrafts.value.eventEna) {
        globalDrafts.value.eventEna = {};
    }

    globalDrafts.value.eventEna[`${code}:${eventId}`] = value;
}

function clearEventSecret(code, eventId) {
    eventDrafts.value[code][eventId] = '';
    storedEvent.value[code][eventId] = '';
    clearedSecrets.value[code] = clearEventSecretHelper(code, eventId);
}

function clearEventSecretHelper(code, eventId) {
    const list = clearedSecrets.value[code] || [];

    return [...list.filter((k) => !k.startsWith(`ev:${eventId}:`)), `ev:${eventId}:access_token`];
}

async function testMethod(code) {
    testing.value[code] = true;

    try {
        const { data } = await api.post(`tickets-admin/payment-methods/${code}/test`);

        toast(data.message, data.ok ? 'success' : 'danger');
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        testing.value[code] = false;
    }
}

async function validatePayments() {
    validating.value = true;

    try {
        const { data } = await api.post('tickets-admin/payment-methods/multipago/validate', {
            desde: validateFrom.value || null,
            hasta: validateTo.value || null,
        });

        const s = data.summary;
        toast(
            `Validacion: ${s.insertadas} insertadas · ${s.duplicadas} duplicadas · ${s.sin_procesar} sin procesar (${s.total} total).`,
            s.insertadas > 0 ? 'success' : 'info',
        );
    } catch (err) {
        toast(toError(err).message, 'danger');
    } finally {
        validating.value = false;
    }
}

async function refreshWebhookUrl(eventId) {
    try {
        const { data } = await api.get(`tickets-admin/payment-methods/multipago/webhook-url/${eventId}`);

        webhookUrls.value[eventId] = data.url;
    } catch {
        webhookUrls.value[eventId] = '';
    }
}

async function copyToClipboard(text, label) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = label;
        setTimeout(() => { copied.value = ''; }, 1500);
    } catch {
        toast('No se pudo copiar', 'warning');
    }
}

function connectMpEvent(eventId) {
    window.location.href = `/tickets-admin/config/mp-authorize-url?event_id=${eventId}`;
}

function maskIfSecret(method, key, value) {
    if (hasSecret(method, key)) {
        return '••••••••';
    }

    return value;
}

onMounted(load);
</script>

<template>
    <div>
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h4 mb-1">Medios de pago</h2>
                <p class="text-muted-2 small mb-0">
                    Credenciales de cada proveedor y con que medios cobra cada evento.
                </p>
            </div>
            <div class="form-check form-switch d-none">
                <input class="form-check-input" type="checkbox">
            </div>
        </div>

        <div v-if="loading" class="d-flex justify-content-center py-5">
            <div class="spinner-border text-primary"></div>
        </div>

        <div v-else class="d-flex flex-column gap-3">
            <section
                v-for="method in methods"
                :key="method.code"
                class="et-surface-raised"
            >
                <!-- Cabecera del acordeon -->
                <button
                    type="button"
                    class="payment-method-head"
                    @click="toggleOpen(method.code)"
                >
                    <i class="bi" :class="method.code === 'mercadopago' ? 'bi-bank' : 'bi-qr-code-scan'"></i>
                    <span class="fw-bold">{{ method.name }}</span>

                    <span
                        class="et-badge"
                        :class="method.enabled ? 'et-badge--success' : 'et-badge--warning'"
                        @click.stop
                    >
                        <label class="form-check form-switch form-switch-sm mb-0">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                :checked="method.enabled"
                                @change="(ev) => {
                                    globalDrafts.enabled = globalDrafts.enabled || {};
                                    globalDrafts.enabled[method.code] = ev.target.checked;
                                    method.enabled = ev.target.checked;
                                    saveGlobal(method.code);
                                }"
                            >
                        </label>
                        {{ method.enabled ? 'Habilitado' : 'Deshabilitado' }}
                    </span>

                    <span class="ms-auto">
                        <button
                            type="button"
                            class="btn btn-et-ghost btn-sm"
                            @click.stop="testMethod(method.code)"
                            :disabled="testing[method.code]"
                        >
                            <span
                                v-if="testing[method.code]"
                                class="spinner-border spinner-border-sm me-1"
                            ></span>
                            Probar conexion
                        </button>
                        <i
                            class="bi bi-chevron-down ms-2"
                            :class="{ 'is-open': open[method.code] }"
                        ></i>
                    </span>
                </button>

                <div v-if="open[method.code]" class="p-3 border-top">
                    <template v-if="method.code === 'multipago'">
                        <div class="alert alert-info small py-2 mb-3">
                            <span class="me-2">{{ method.name }}</span>
                            <button
                                type="button"
                                class="btn btn-et-primary btn-sm"
                                :disabled="validating"
                                @click="validatePayments"
                            >
                                <span
                                    v-if="validating"
                                    class="spinner-border spinner-border-sm me-1"
                                ></span>
                                <i class="bi bi-arrow-repeat me-1"></i>
                                Validar pagos (consultar_deuda)
                            </button>
                            <span class="d-inline-flex gap-1 ms-2 align-items-center">
                                <input
                                    type="date"
                                    class="form-control form-control-sm"
                                    style="width: auto"
                                    v-model="validateFrom"
                                >
                                <span>a</span>
                                <input
                                    type="date"
                                    class="form-control form-control-sm"
                                    style="width: auto"
                                    v-model="validateTo"
                                >
                            </span>
                        </div>
                    </template>

                    <!-- Config global del proveedor -->
                    <h3 class="h6 fw-bold mb-2">Cuenta del proveedor (global)</h3>
                    <div class="row g-2 align-items-end mb-2">
                        <div
                            v-for="field in FIELDS[method.code]?.global || []"
                            :key="field.key"
                            class="col-md-4"
                        >
                            <label class="form-label small mb-1" :for="`${method.code}-${field.key}`">
                                {{ field.label }}
                                <button
                                    v-if="hasSecret(method, field.key)"
                                    type="button"
                                    class="btn btn-link btn-sm p-0 ms-2 text-danger"
                                    @click="clearSecret(method.code, field.key, globalDrafts[method.code])"
                                >
                                    Quitar
                                </button>
                            </label>
                            <input
                                :id="`${method.code}-${field.key}`"
                                v-model="globalDrafts[method.code][field.key]"
                                type="text"
                                class="form-control form-control-sm"
                                autocomplete="off"
                                spellcheck="false"
                                :placeholder="hasSecret(method, field.key)
                                    ? '•••••••• (guardado)'
                                    : field.key"
                            >
                            <div
                                v-if="hasSecret(method, field.key)"
                                class="form-text small text-success"
                            >
                                <i class="bi bi-check-circle-fill me-1"></i>
                                Secreto guardado. Dejalo vacio para no cambiarlo.
                            </div>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn btn-et-primary btn-sm"
                        :disabled="saving[method.code]"
                        @click="saveGlobal(method.code)"
                    >
                        <span
                            v-if="saving[method.code]"
                            class="spinner-border spinner-border-sm me-1"
                        ></span>
                        Guardar config
                    </button>

                    <hr class="my-3">

                    <!-- Config por evento -->
                    <h3 class="h6 fw-bold mb-1">{{ FIELDS[method.code]?.eventTitle }}</h3>
                    <p class="small text-muted-2 mb-2">{{ FIELDS[method.code]?.eventHint }}</p>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Evento</th>
                                    <th style="width: 110px">Habilitado</th>
                                    <th>Credencial del evento</th>
                                    <th v-if="method.code === 'multipago'" style="width: 300px">Webhook URL</th>
                                    <th style="width: 130px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="event in method.events || []" :key="event.event_id">
                                    <td>
                                        <span class="fw-semibold">{{ event.event_name }}</span>
                                    </td>
                                    <td>
                                        <div class="form-check form-switch">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                :checked="globalDrafts.eventEna?.[`${method.code}:${event.event_id}`] ?? event.enabled"
                                                @change="(ev) => {
                                                    toggleEvent(method.code, event.event_id, ev.target.checked);
                                                }"
                                            >
                                        </div>
                                    </td>
                                    <td>
                                        <template v-if="method.code === 'mercadopago'">
                                            <div class="input-group input-group-sm">
                                                <input
                                                    v-model="eventDrafts[method.code][event.event_id]"
                                                    type="text"
                                                    class="form-control form-control-sm"
                                                    autocomplete="off"
                                                    spellcheck="false"
                                                    :placeholder="eventHasToken(event, method.code)
                                                        ? '•••••••• (token guardado)'
                                                        : 'APP_USR-... o TEST-... (opcional)'"
                                                >
                                                <button
                                                    v-if="eventHasToken(event, method.code)"
                                                    type="button"
                                                    class="btn btn-outline-danger btn-sm"
                                                    title="Quitar token del evento"
                                                    @click="clearEventSecret(method.code, event.event_id)"
                                                >
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                            <div class="form-text small">
                                                <button
                                                    type="button"
                                                    class="btn btn-link btn-sm p-0"
                                                    @click="connectMpEvent(event.event_id)"
                                                >
                                                    Conectar via OAuth
                                                </button>
                                            </div>
                                        </template>
                                        <template v-else>
                                            <span class="small text-muted-2">
                                                La key se genera sola al guardar el evento.
                                            </span>
                                        </template>
                                    </td>
                                    <td v-if="method.code === 'multipago'">
                                        <div v-if="webhookUrls[event.event_id]" class="input-group input-group-sm">
                                            <input
                                                :value="webhookUrls[event.event_id]"
                                                type="text"
                                                readonly
                                                class="form-control form-control-sm font-monospace"
                                                @focus="$event.target.select()"
                                            >
                                            <button
                                                type="button"
                                                class="btn btn-outline-secondary btn-sm"
                                                @click="copyToClipboard(webhookUrls[event.event_id], `webhook-${event.event_id}`)"
                                            >
                                                <i
                                                    class="bi"
                                                    :class="copied === `webhook-${event.event_id}` ? 'bi-check' : 'bi-clipboard'"
                                                ></i>
                                            </button>
                                        </div>
                                        <span v-else class="small text-muted-2">
                                            Guarda el evento para generar la URL.
                                        </span>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn btn-et-primary btn-sm w-100"
                                            :disabled="savingEvent[`${method.code}:${event.event_id}`]"
                                            @click="saveEvent(method.code, event.event_id)"
                                        >
                                            <span
                                                v-if="savingEvent[`${method.code}:${event.event_id}`]"
                                                class="spinner-border spinner-border-sm me-1"
                                            ></span>
                                            Guardar
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
.payment-method-head {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    width: 100%;
    padding: 0.85rem 1rem;
    border: 0;
    background: none;
    color: var(--et-text);
    font-size: 1rem;
    text-align: left;
}

.payment-method-head .bi-chevron-down {
    transition: transform var(--et-transition);
}

.payment-method-head .bi-chevron-down.is-open {
    transform: rotate(180deg);
}
</style>