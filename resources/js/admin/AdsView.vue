<script setup>
/**
 * Publicidad del portal.
 *
 * Los anuncios se publican por posicion (top, banner, sidebar, modal) y pueden
 * ser globales o de un evento. La vigencia se controla con start_at/end_at, no
 * con el interruptor: enable apaga, pero un anuncio programmed puede seguir
 * esperando su fecha sin verse.
 */
import { ref, reactive, watch, onMounted } from 'vue';
import api, { toError } from '../api.js';
import EmptyState from '../ui/EmptyState.vue';
import ImageFlyer from '../ui/ImageFlyer.vue';
import { toast } from '../ui/toast.js';

const ads = ref([]);
const events = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });

const filters = reactive({ event_id: '', page: 1 });
const loading = ref(true);
const error = ref('');

const editor = ref(null);
const editorBusy = ref(false);
const editorError = ref('');
const uploading = ref(false);

const POSITIONS = [
    { value: 'top', label: 'Superior' },
    { value: 'banner', label: 'Banner' },
    { value: 'sidebar', label: 'Lateral' },
    { value: 'modal', label: 'Modal' },
];

function blankAd() {
    return {
        mode: 'create',
        event_id: '',
        name: '',
        image_path: '',
        target_url: '',
        position: 'banner',
        sort_order: 0,
        enable: true,
        start_at: '',
        end_at: '',
    };
}

async function load() {
    try {
        const { data } = await api.get('tickets-admin/ads', {
            event_id: filters.event_id || undefined,
            page: filters.page,
        });

        ads.value = data.data || [];
        meta.value = {
            current_page: data.current_page || 1,
            last_page: data.last_page || 1,
            total: data.total || 0,
        };
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
    } catch {
        // Se puede publicar publicidad global sin eventos cargados.
    }
}

function toLocalInput(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());

    return date.toISOString().slice(0, 16);
}

function positionLabel(position) {
    return POSITIONS.find((item) => item.value === position)?.label || position;
}

function formatDay(value) {
    return new Date(value).toLocaleDateString('es-AR');
}

function windowLabel(ad) {
    if (!ad.start_at && !ad.end_at) {
        return 'Vigente siempre';
    }

    const from = ad.start_at ? `Desde ${formatDay(ad.start_at)}` : 'Sin inicio';
    const to = ad.end_at ? `Hasta ${formatDay(ad.end_at)}` : 'Sin fin';

    return `${from} · ${to}`;
}

function eventName(eventId) {
    if (!eventId) {
        return 'Global';
    }

    return events.value.find((event) => event.id === eventId)?.name || `Evento #${eventId}`;
}

function openCreate() {
    editorError.value = '';
    editor.value = blankAd();
}

function openEdit(ad) {
    editorError.value = '';
    editor.value = {
        mode: 'edit',
        id: ad.id,
        event_id: ad.event_id || '',
        name: ad.name,
        image_path: ad.image_path,
        target_url: ad.target_url || '',
        position: ad.position,
        sort_order: ad.sort_order ?? 0,
        enable: Boolean(ad.enable),
        start_at: toLocalInput(ad.start_at),
        end_at: toLocalInput(ad.end_at),
    };
}

function closeEditor() {
    editor.value = null;
    editorError.value = '';
}

async function uploadImage(event) {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    uploading.value = true;

    try {
        const body = new FormData();

        body.append('file', file);

        const { data } = await api.post('tickets-admin/ads/upload', body);

        editor.value.image_path = data.url;
        toast('Imagen subida', 'success');
    } catch (err) {
        editorError.value = toError(err).message;
    } finally {
        uploading.value = false;
        event.target.value = '';
    }
}

function payload() {
    return {
        event_id: editor.value.event_id || null,
        name: editor.value.name,
        image_path: editor.value.image_path,
        target_url: editor.value.target_url || null,
        position: editor.value.position,
        sort_order: Number(editor.value.sort_order) || 0,
        enable: editor.value.enable,
        start_at: editor.value.start_at || null,
        end_at: editor.value.end_at || null,
    };
}

async function submitEditor() {
    editorBusy.value = true;
    editorError.value = '';

    try {
        if (editor.value.mode === 'create') {
            await api.post('tickets-admin/ads', payload());

            toast('Publicidad creada', 'success');
        } else {
            await api.put(`tickets-admin/ads/${editor.value.id}`, payload());

            toast('Publicidad actualizada', 'success');
        }

        closeEditor();
        await load();
    } catch (err) {
        editorError.value = toError(err).message;
    } finally {
        editorBusy.value = false;
    }
}

async function toggleEnable(ad) {
    try {
        await api.put(`tickets-admin/ads/${ad.id}`, { enable: !ad.enable });

        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    }
}

async function destroy(ad) {
    if (!window.confirm(`Eliminar la publicidad "${ad.name}"?`)) {
        return;
    }

    try {
        await api.delete(`tickets-admin/ads/${ad.id}`);

        toast('Publicidad eliminada', 'success');
        await load();
    } catch (err) {
        toast(toError(err).message, 'danger');
    }
}

function goToPage(page) {
    filters.page = page;
    load();
}

watch(() => filters.event_id, () => {
    filters.page = 1;
    load();
});

onMounted(async () => {
    await Promise.all([load(), loadEvents()]);
});
</script>

<template>
    <div>
        <header class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h2 class="h5 fw-bold mb-0">Publicidad</h2>
                <p class="text-muted-2 small mb-0">{{ meta.total }} anuncios</p>
            </div>

            <button class="btn btn-et-primary btn-sm" @click="openCreate">
                <i class="bi bi-plus-lg me-1"></i>Nuevo anuncio
            </button>
        </header>

        <div class="et-surface-raised p-3 mb-3">
            <label class="form-label" for="event">Evento</label>
            <select id="event" v-model="filters.event_id" class="form-select">
                <option value="">Todos</option>
                <option v-for="event in events" :key="event.id" :value="event.id">
                    {{ event.name }}
                </option>
            </select>
        </div>

        <p v-if="error" class="small" style="color: var(--et-danger)">
            <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
        </p>

        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border" role="status"></div>
        </div>

        <EmptyState
            v-else-if="!ads.length"
            icon="bi-badge-ad"
            title="Sin publicidad"
            hint="Los anuncios del portal se administran desde aca."
        />

        <div v-else class="row g-2">
            <div v-for="ad in ads" :key="ad.id" class="col-12 col-md-6 col-xl-4">
                <article class="ad et-surface">
                    <ImageFlyer
                        :src="ad.image_path"
                        :alt="`Publicidad: ${ad.name}`"
                        thumb-class="ad__img"
                    />

                    <div class="ad__body">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="fw-semibold">{{ ad.name }}</div>
                            <span class="et-badge" :class="ad.enable ? 'et-badge--success' : 'et-badge--muted'">
                                {{ ad.enable ? 'Activo' : 'Inactivo' }}
                            </span>
                        </div>

                        <div class="small text-faint mt-1">
                            {{ positionLabel(ad.position) }} · {{ eventName(ad.event_id) }}
                        </div>

                        <div v-if="ad.target_url" class="small text-faint text-truncate mt-1">
                            <i class="bi bi-link-45deg me-1"></i>{{ ad.target_url }}
                        </div>

                        <div v-if="ad.start_at || ad.end_at" class="small text-faint numeric mt-1">
                            {{ windowLabel(ad) }}
                        </div>
                    </div>

                    <footer class="ad__foot">
                        <button class="btn btn-et-ghost btn-sm" @click="openEdit(ad)">Editar</button>
                        <button class="btn btn-et-ghost btn-sm" @click="toggleEnable(ad)">
                            {{ ad.enable ? 'Desactivar' : 'Activar' }}
                        </button>
                        <button
                            class="btn btn-et-ghost btn-sm btn-danger-soft"
                            @click="destroy(ad)"
                        >
                            Eliminar
                        </button>
                    </footer>
                </article>
            </div>
        </div>

        <nav v-if="meta.last_page > 1" class="pager">
            <button
                class="btn btn-et-ghost btn-sm"
                :disabled="meta.current_page <= 1"
                @click="goToPage(meta.current_page - 1)"
            >
                Anterior
            </button>
            <span class="small text-muted-2 numeric">{{ meta.current_page }} / {{ meta.last_page }}</span>
            <button
                class="btn btn-et-ghost btn-sm"
                :disabled="meta.current_page >= meta.last_page"
                @click="goToPage(meta.current_page + 1)"
            >
                Siguiente
            </button>
        </nav>

        <!-- Editor -->
        <div v-if="editor" class="modal-backdrop" @click.self="closeEditor">
            <div class="modal-sheet">
                <header class="modal-sheet__head">
                    <h3 class="h6 fw-bold mb-0">
                        {{ editor.mode === 'create' ? 'Nuevo anuncio' : 'Editar anuncio' }}
                    </h3>
                    <button class="btn btn-link btn-sm p-0" @click="closeEditor">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </header>

                <form class="modal-sheet__body" @submit.prevent="submitEditor">
                    <p v-if="editorError" class="et-alert et-alert--danger">{{ editorError }}</p>

                    <div class="mb-3">
                        <label class="form-label" for="ad-name">Nombre</label>
                        <input id="ad-name" v-model="editor.name" class="form-control" required maxlength="150">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="ad-image">Imagen</label>
                        <input
                            id="ad-image"
                            type="file"
                            class="form-control"
                            accept="image/*"
                            :disabled="uploading"
                            @change="uploadImage"
                        >
                        <div v-if="uploading" class="form-text">Subiendo imagen…</div>
                        <div v-else-if="editor.image_path" class="mt-2 ad__preview-wrap">
                            <ImageFlyer
                                :src="editor.image_path"
                                alt="Vista previa"
                                thumb-class="ad__preview"
                            />
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="ad-position">Posicion</label>
                            <select id="ad-position" v-model="editor.position" class="form-select" required>
                                <option v-for="option in POSITIONS" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <div class="col-6">
                            <label class="form-label" for="ad-order">Orden</label>
                            <input id="ad-order" v-model="editor.sort_order" type="number" class="form-control">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="ad-event">Evento</label>
                        <select id="ad-event" v-model="editor.event_id" class="form-select">
                            <option value="">Global (todos los eventos)</option>
                            <option v-for="event in events" :key="event.id" :value="event.id">
                                {{ event.name }}
                            </option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="ad-url">Link destino</label>
                        <input
                            id="ad-url"
                            v-model="editor.target_url"
                            type="url"
                            class="form-control"
                            placeholder="https://…"
                        >
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="ad-start">Desde</label>
                            <input id="ad-start" v-model="editor.start_at" type="datetime-local" class="form-control">
                        </div>

                        <div class="col-6">
                            <label class="form-label" for="ad-end">Hasta</label>
                            <input id="ad-end" v-model="editor.end_at" type="datetime-local" class="form-control">
                        </div>
                    </div>

                    <div class="form-check form-switch">
                        <input
                            id="ad-enable"
                            v-model="editor.enable"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                        >
                        <label class="form-check-label" for="ad-enable">Anuncio activo</label>
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
.ad {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
}

/*
| :deep() para que las clases thumb-class del ImageFlyer matcheen desde aca
| (el button raiz del ImageFlyer solo lleva su propio data-v, no el del
| componente padre, asi que las reglas scoped normales no aplican).
*/
.ad :deep(.ad__img) {
    width: 100%;
    aspect-ratio: 21 / 9;
    background: var(--et-surface-hover);
}

.ad__preview-wrap :deep(.ad__preview) {
    max-width: 180px;
    border-radius: var(--et-radius-sm);
    border: 1px solid var(--et-border);
}

.ad__body {
    padding: 0.75rem;
    flex: 1;
}

.ad__foot {
    display: flex;
    gap: 0.4rem;
    padding: 0 0.75rem 0.75rem;
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