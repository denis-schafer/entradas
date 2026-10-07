<script setup>
/**
 * Cambio de contrasena del operador.
 *
 * Es una pantalla propia y no una seccion de Configuracion a proposito: cuando
 * el admin entra con la contrasena temporal, el middleware le responde 428 a
 * TODO el panel salvo a este endpoint. Si el formulario viviera adentro de otra
 * pantalla, esa pantalla no podria ni cargar sus propios datos y el admin
 * quedaria sin salida.
 *
 * Sirve para dos cosas: destrabar el primer ingreso y cambiar la contrasena
 * cuando uno quiera.
 */
import { reactive, ref, computed } from 'vue';
import api, { toError } from '../api.js';
import { toast } from '../ui/toast.js';

const props = defineProps({
    // El flag se pasa aparte porque el usuario que trae el shell se actualiza
    // recien cuando la contrasena quedo cambiada de verdad.
    required: { type: Boolean, default: false },
    user: { type: Object, default: null },
});

const emit = defineEmits(['changed']);

const form = reactive({ current: '', password: '', confirmation: '' });
const error = ref('');
const busy = ref(false);

const MIN = 8;

const length = computed(() => form.password.length);
const longEnough = computed(() => length.value >= MIN);
const matches = computed(() => form.password === form.confirmation && form.confirmation !== '');
const canSubmit = computed(() => form.current.length > 0 && longEnough.value && matches.value && !busy.value);

function strength() {
    if (length.value === 0) {
        return 'empty';
    }

    if (length.value < MIN) {
        return 'short';
    }

    return /[a-z]/.test(form.password) && /[A-Z0-9]/.test(form.password) ? 'good' : 'ok';
}

async function submit() {
    if (!canSubmit.value) {
        return;
    }

    busy.value = true;
    error.value = '';

    try {
        await api.post('tickets-admin/auth/change-password', {
            current_password: form.current,
            password: form.password,
            password_confirmation: form.confirmation,
        });

        form.current = '';
        form.password = '';
        form.confirmation = '';

        toast('Contrasena actualizada', 'success');

        emit('changed');
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="password">
        <header class="password__head">
            <h1 class="h5 fw-bold mb-1">Tu contrasena</h1>
            <p class="text-muted-2 mb-0">
                Cambiala cuando quieras. Si seguis usando la temporal, el panel
                queda bloqueado hasta que la cambies.
            </p>
        </header>

        <div v-if="required" class="et-alert et-alert--warning mb-4">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>Estas con la contrasena temporal.</strong>
            Elegi una nueva para poder operar: hasta entonces el panel no te
            deja ver nada.
        </div>

        <form class="password__card et-surface-raised" novalidate @submit.prevent="submit">
            <div class="mb-3">
                <label class="form-label" for="current">Contrasena actual</label>
                <input
                    id="current"
                    v-model="form.current"
                    type="password"
                    autocomplete="current-password"
                    class="form-control"
                >
                <div class="form-text">
                    {{ user?.email || user?.dni || '' }}
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="new-password">Contrasena nueva</label>
                <input
                    id="new-password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    class="form-control"
                >
                <div class="password__meter">
                    <span class="password__bar" :class="`password__bar--${strength()}`"></span>
                    <small class="text-muted-2">
                        <template v-if="strength() === 'empty'">Minimo {{ MIN }} caracteres.</template>
                        <template v-else-if="strength() === 'short'">
                            Le faltan {{ MIN - length }} caracteres.
                        </template>
                        <template v-else-if="strength() === 'ok'">Larga. Agrega una mayuscula o un numero.</template>
                        <template v-else>Buena.</template>
                    </small>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="confirm-password">Repetir la nueva</label>
                <input
                    id="confirm-password"
                    v-model="form.confirmation"
                    type="password"
                    autocomplete="new-password"
                    class="form-control"
                    :class="{ 'is-invalid': form.confirmation && !matches }"
                >
                <div v-if="form.confirmation && !matches" class="invalid-feedback d-block">
                    Las contrasenas no coinciden.
                </div>
            </div>

            <p v-if="error" class="small mb-3" style="color: var(--et-danger)">
                <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
            </p>

            <button class="btn btn-et-primary" type="submit" :disabled="!canSubmit">
                <span v-if="busy" class="spinner-border spinner-border-sm me-2"></span>
                Guardar contrasena
            </button>
        </form>
    </section>
</template>

<style scoped>
.password {
    max-width: 520px;
}

.password__head {
    margin-bottom: 1.25rem;
}

.password__card {
    padding: 1.5rem;
    border-radius: var(--et-radius-lg);
}

.password__meter {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin-top: 0.5rem;
}

.password__bar {
    height: 4px;
    flex: 1;
    border-radius: 2px;
    background: var(--et-border);
    transition: background-color 0.15s ease;
}

.password__bar--empty {
    background: var(--et-border);
}

.password__bar--short {
    background: var(--et-danger);
}

.password__bar--ok {
    background: var(--et-warning, #d9a441);
}

.password__bar--good {
    background: var(--et-success);
}
</style>