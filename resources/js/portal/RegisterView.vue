<script setup>
/**
 * Alta de comprador. El DNI es la identidad del portal, asi que se pide con
 * el formato que la persona tiene a mano ("29.923.360") y el backend lo
 * normaliza antes de guardarlo.
 */
import { ref, reactive, computed } from 'vue';
import api, { toError } from '../api.js';
import { toast } from '../ui/toast.js';

const emit = defineEmits(['logged-in', 'navigate']);

const form = reactive({
    name: '',
    email: '',
    dni: '',
    phone: '',
    password: '',
    password_confirmation: '',
    consent: false,
});

const error = ref('');
const fieldErrors = ref({});
const busy = ref(false);

const canSubmit = computed(() => (
    form.name.trim()
    && form.email.trim()
    && form.dni.trim().length > 0
    && form.password.length >= 8
    && form.password === form.password_confirmation
    && form.consent
));

function invalid(field) {
    return fieldErrors.value[field]?.[0] || '';
}

async function submit() {
    if (!canSubmit.value || busy.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    fieldErrors.value = {};

    try {
        const { data } = await api.post('tickets-portal/api/auth/register', {
            ...form,
            dni: form.dni.trim(),
            password_confirmation: form.password_confirmation,
        });

        emit('logged-in', data.user);
        toast('Cuenta creada. Ya podes comprar.', 'success');
    } catch (err) {
        const formatted = toError(err);

        error.value = formatted.message;
        fieldErrors.value = formatted.errors;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="auth">
        <div class="auth__card et-surface-raised">
            <div class="auth__mark"></div>

            <h1 class="h4 fw-bold mb-1">Creá tu cuenta</h1>
            <p class="text-muted-2 mb-4">Tus entradas quedan siempre a mano con tu DNI.</p>

            <form novalidate @submit.prevent="submit">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="name">Nombre y apellido</label>
                        <input
                            id="name" v-model="form.name" type="text" autocomplete="name"
                            class="form-control" :class="{ 'is-invalid': invalid('name') }"
                        >
                        <div v-if="invalid('name')" class="invalid-feedback">{{ invalid('name') }}</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="email">Email</label>
                        <input
                            id="email" v-model="form.email" type="email" autocomplete="email"
                            class="form-control" :class="{ 'is-invalid': invalid('email') }"
                        >
                        <div v-if="invalid('email')" class="invalid-feedback">{{ invalid('email') }}</div>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label" for="dni">DNI</label>
                        <input
                            id="dni" v-model="form.dni" type="text" inputmode="numeric"
                            class="form-control" :class="{ 'is-invalid': invalid('dni') }"
                            placeholder="29.923.360"
                        >
                        <div v-if="invalid('dni')" class="invalid-feedback">{{ invalid('dni') }}</div>
                    </div>

                    <div class="col-sm-6">
                        <label class="form-label" for="phone">Telefono</label>
                        <input
                            id="phone" v-model="form.phone" type="tel" autocomplete="tel"
                            class="form-control" :class="{ 'is-invalid': invalid('phone') }"
                        >
                        <div v-if="invalid('phone')" class="invalid-feedback">{{ invalid('phone') }}</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="password">Contrasena</label>
                        <input
                            id="password" v-model="form.password" type="password"
                            autocomplete="new-password"
                            class="form-control" :class="{ 'is-invalid': invalid('password') }"
                        >
                        <div class="form-text">Minimo 8 caracteres.</div>
                        <div v-if="invalid('password')" class="invalid-feedback">{{ invalid('password') }}</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="password2">Repeti la contrasena</label>
                        <input
                            id="password2" v-model="form.password_confirmation" type="password"
                            autocomplete="new-password"
                            class="form-control"
                            :class="{ 'is-invalid': form.password !== form.password_confirmation }"
                        >
                        <div
                            v-if="form.password !== form.password_confirmation"
                            class="invalid-feedback"
                        >
                            Las contrasenas no coinciden.
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input
                                id="consent" v-model="form.consent"
                                class="form-check-input" type="checkbox"
                            >
                            <label class="form-check-label small" for="consent">
                                Acepto el tratamiento de mis datos para emitir entradas.
                            </label>
                        </div>
                        <div v-if="invalid('consent')" class="invalid-feedback d-block">
                            {{ invalid('consent') }}
                        </div>
                    </div>
                </div>

                <p v-if="error" class="small mt-3 mb-3" style="color: var(--et-danger)">
                    <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
                </p>

                <button class="btn btn-et-primary w-100 mt-3" type="submit" :disabled="!canSubmit || busy">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-2"></span>
                    Crear cuenta
                </button>
            </form>

            <hr class="et-divider my-4">

            <p class="text-muted-2 small mb-0 text-center">
                Ya tenes cuenta?
                <button class="btn btn-link btn-sm p-0 align-baseline" @click="emit('navigate', 'login')">
                    Ingresar
                </button>
            </p>
        </div>
    </div>
</template>

<style scoped>
.auth {
    min-height: 62vh;
    display: grid;
    place-items: center;
}

.auth__card {
    width: min(480px, 100%);
    padding: 2rem 1.75rem;
    border-radius: var(--et-radius-lg);
}

.auth__mark {
    width: 42px;
    height: 42px;
    border-radius: 13px;
    background: linear-gradient(135deg, var(--et-primary), var(--et-accent));
    margin-bottom: 1.25rem;
}
</style>