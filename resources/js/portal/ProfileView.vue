<script setup>
/**
 * Datos del comprador y cambio de contrasena.
 *
 * El DNI NO se edita: es la identidad con la que entra y cambiarlo en caliente
 * dejaria las compras anteriores sin dueno. Si el comprador se equivoco al
 * registrarse, el camino es el de abajo, no un campo editable.
 */
import { ref, reactive, computed } from 'vue';
import api, { toError } from '../api.js';
import { toast } from '../ui/toast.js';

const props = defineProps({
    user: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['logged-in']);

const profile = reactive({
    name: props.user.name || '',
    email: props.user.email || '',
    phone: props.user.phone || '',
});

const password = reactive({
    current: '',
    next: '',
    confirmation: '',
});

const profileBusy = ref(false);
const passwordBusy = ref(false);
const profileError = ref('');
const passwordError = ref('');

const canSaveProfile = computed(() => (
    profile.name.trim()
    && profile.email.trim()
    && (profile.name !== props.user.name
        || profile.email !== props.user.email
        || profile.phone !== (props.user.phone || ''))
    && !profileBusy.value
));

const canSavePassword = computed(() => (
    password.current
    && password.next.length >= 8
    && password.next === password.confirmation
    && !passwordBusy.value
));

async function saveProfile() {
    if (!canSaveProfile.value) {
        return;
    }

    profileBusy.value = true;
    profileError.value = '';

    try {
        const { data } = await api.put('tickets-portal/api/auth/profile', {
            name: profile.name.trim(),
            email: profile.email.trim(),
            phone: profile.phone.trim(),
        });

        // El usuario actualizado tiene que viajar hacia arriba: el shell lo
        // guarda y toda la app deja de mostrar el nombre viejo.
        emit('logged-in', data.user);
        toast('Datos actualizados', 'success');
    } catch (err) {
        profileError.value = toError(err).message;
    } finally {
        profileBusy.value = false;
    }
}

async function savePassword() {
    if (!canSavePassword.value) {
        return;
    }

    passwordBusy.value = true;
    passwordError.value = '';

    try {
        await api.post('tickets-portal/api/auth/change-password', {
            current_password: password.current,
            password: password.next,
            password_confirmation: password.confirmation,
        });

        password.current = '';
        password.next = '';
        password.confirmation = '';

        toast('Contrasena actualizada', 'success');
    } catch (err) {
        passwordError.value = toError(err).message;
    } finally {
        passwordBusy.value = false;
    }
}
</script>

<template>
    <div class="profile">
        <header class="mb-4">
            <h1 class="h3 fw-bold mb-1">Mi cuenta</h1>
            <p class="text-muted-2 mb-0">Tus datos y tu contrasena.</p>
        </header>

        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <section class="et-surface-raised p-3 h-100">
                    <h2 class="h6 fw-bold mb-3">Datos</h2>

                    <form novalidate @submit.prevent="saveProfile">
                        <div class="mb-3">
                            <label class="form-label" for="name">Nombre y apellido</label>
                            <input id="name" v-model="profile.name" type="text" class="form-control">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="email">Email</label>
                            <input id="email" v-model="profile.email" type="email" class="form-control">
                            <div
                                v-if="profileError"
                                class="form-text"
                                style="color: var(--et-danger)"
                            >
                                {{ profileError }}
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="phone">Telefono</label>
                            <input id="phone" v-model="profile.phone" type="tel" class="form-control">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="dni">DNI</label>
                            <input
                                id="dni"
                                :value="user.dni"
                                type="text"
                                class="form-control"
                                disabled
                            >
                            <div class="form-text">
                                Es con lo que inicias sesion, por eso no se puede cambiar.
                            </div>
                        </div>

                        <button class="btn btn-et-primary" type="submit" :disabled="!canSaveProfile">
                            <span v-if="profileBusy" class="spinner-border spinner-border-sm me-2"></span>
                            Guardar cambios
                        </button>
                    </form>
                </section>
            </div>

            <div class="col-12 col-lg-6">
                <section class="et-surface-raised p-3 h-100">
                    <h2 class="h6 fw-bold mb-3">Contrasena</h2>

                    <form novalidate @submit.prevent="savePassword">
                        <div class="mb-3">
                            <label class="form-label" for="current">Contrasena actual</label>
                            <input
                                id="current"
                                v-model="password.current"
                                type="password"
                                autocomplete="current-password"
                                class="form-control"
                            >
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="next">Nueva contrasena</label>
                            <input
                                id="next"
                                v-model="password.next"
                                type="password"
                                autocomplete="new-password"
                                class="form-control"
                            >
                            <div class="form-text">Minimo 8 caracteres.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="confirmation">Repetir la nueva</label>
                            <input
                                id="confirmation"
                                v-model="password.confirmation"
                                type="password"
                                autocomplete="new-password"
                                class="form-control"
                                :class="{ 'is-invalid': password.confirmation && password.next !== password.confirmation }"
                            >
                            <div
                                v-if="password.confirmation && password.next !== password.confirmation"
                                class="invalid-feedback"
                            >
                                No coinciden.
                            </div>
                        </div>

                        <p v-if="passwordError" class="small" style="color: var(--et-danger)">
                            <i class="bi bi-exclamation-circle me-1"></i>{{ passwordError }}
                        </p>

                        <button class="btn btn-et-primary" type="submit" :disabled="!canSavePassword">
                            <span v-if="passwordBusy" class="spinner-border spinner-border-sm me-2"></span>
                            Cambiar contrasena
                        </button>
                    </form>
                </section>
            </div>
        </div>
    </div>
</template>

<style scoped>
.profile {
    max-width: 900px;
    margin: 0 auto;
}
</style>