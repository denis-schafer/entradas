<script setup>
/**
 * Unico login de la app.
 *
 * Una sola forma, un solo campo. El usuario escribe su email o su DNI y el
 * backend decide: si es un operador entra al panel, si es un comprador entra al
 * portal. El que decide adonde va es el is_admin de la respuesta, no el usuario,
 * asi que no hace falta un selector de "soy administrador" que se puede elegir
 * mal y dejar a alguien del equipo trabado en el portal.
 *
 * El campo se etiqueta como DNI porque el que mas usa esto es el comprador: el
 * texto "Email o DNI" lo hace dudar sobre si el DNI es opcional. El personal del
 * evento igual puede escribir su email, asi que la aclaracion esta en el texto de
 * ayuda y no como una segunda opcion a elegir.
 *
 * La sesion es una sola de Laravel: entrar es entrar, y el shell que monta la
 * raiz depende de is_admin.
 */
import { ref, reactive, computed } from 'vue';
import api, { toError } from '../api.js';
import { toast } from '../ui/toast.js';

const emit = defineEmits(['logged-in', 'navigate']);

// Se declaran aunque no se usen: el PortalShell las pasa por v-bind (junto con
// los params de ruta) y, si no fueran props, Vue las volcaria como atributos
// sueltos en el DOM.
defineProps({
    user: { type: Object, default: null },
    config: { type: Object, default: () => ({}) },
});

const form = reactive({ identifier: '', password: '' });
const error = ref('');
const busy = ref(false);

const canSubmit = computed(() => form.identifier.trim() && form.password.length > 0);

async function submit() {
    if (!canSubmit.value || busy.value) {
        return;
    }

    busy.value = true;
    error.value = '';

    try {
        const { data } = await api.post('tickets-auth/login', {
            identifier: form.identifier.trim(),
            password: form.password,
        });

        // El shell que monta la raiz depende de is_admin de la respuesta, no
        // de aca: emitir la navegacion desde este componente seria pedir una
        // pantalla que todavia no existe en el router.
        emit('logged-in', data.user);

        toast(`Bienvenido, ${String(data.user.name || '').split(' ')[0]}`, 'success');
    } catch (err) {
        error.value = toError(err).message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="auth">
        <header class="auth__head">
            <h1 class="h3 fw-bold mb-4">Ingresa a tu cuenta</h1>
        </header>

        <form novalidate @submit.prevent="submit">
            <div class="mb-3">
                <label class="form-label" for="identifier">DNI</label>
                <input
                    id="identifier"
                    v-model="form.identifier"
                    type="text"
                    autocomplete="username"
                    class="form-control"
                    :class="{ 'is-invalid': error }"
                    placeholder="10.222.333"
                >
            </div>

            <div class="mb-3">
                <label class="form-label" for="password">Contrasena</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    class="form-control"
                    :class="{ 'is-invalid': error }"
                >
            </div>

            <p v-if="error" class="small mb-3" style="color: var(--et-danger)">
                <i class="bi bi-exclamation-circle me-1"></i>{{ error }}
            </p>

            <button class="btn btn-et-primary" type="submit" :disabled="!canSubmit || busy">
                <span v-if="busy" class="spinner-border spinner-border-sm me-2"></span>
                Ingresar
            </button>
        </form>
    </div>
</template>

<style scoped>
.auth {
    width: 100%;
    max-width: 420px;
    margin: 0 auto;
}

.auth__head {
    margin-bottom: 0;
}
</style>