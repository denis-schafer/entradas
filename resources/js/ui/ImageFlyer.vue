<script setup>
/**
 * Miniatura con lightbox al hacer click.
 *
 * Pensado para los flyers de los tipos de entrada y la portada del evento en
 * el portal: suelen llevar la informacion del evento en la imagen misma, y la
 * miniatura no alcanza para leerla.
 *
 * Patron tomado de TicketPortalFlyerModal de erden:
 *   - <button> como wrapper (no <img role=button>): accesibilidad nativa.
 *   - Icono de zoom en la esquina que aparece en hover, como pista visual.
 *   - Ligero zoom (1.04) de la imagen en hover.
 *   - Modal con backdrop oscuro y X para cerrar (click afuera o Escape).
 *
 * El lightbox se monta en <body> via Teleport para no quedar recortado por un
 * contenedor padre con overflow. El body recibe la clase `flyer-open` mientras
 * esta abierto para que el CSS global pueda bloquear el scroll si hace falta.
 */
import { ref, watch, onBeforeUnmount } from 'vue';

const props = defineProps({
    src: { type: String, default: '' },
    alt: { type: String, default: '' },
    thumbClass: { type: String, default: '' },
});

const isOpen = ref(false);

function open() {
    if (!props.src) {
        return;
    }
    isOpen.value = true;
}

function close() {
    isOpen.value = false;
}

function onKey(e) {
    if (isOpen.value && e.key === 'Escape') {
        close();
    }
}

watch(isOpen, (val) => {
    document.body.classList.toggle('flyer-open', val);

    if (val) {
        window.addEventListener('keydown', onKey);
    } else {
        window.removeEventListener('keydown', onKey);
    }
}, { immediate: true });

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    document.body.classList.remove('flyer-open');
});
</script>

<template>
    <button
        type="button"
        :class="['image-flyer', thumbClass, { 'image-flyer--empty': !src }]"
        :disabled="!src"
        :title="src ? 'Ver flyer completo' : 'Sin flyer'"
        :aria-label="src ? `${alt || 'Imagen'} (ampliar)` : 'Sin flyer'"
        @click="open"
    >
        <img v-if="src" :src="src" :alt="alt" class="image-flyer__img">
        <span v-else class="image-flyer__placeholder">
            <i class="bi bi-image"></i>
        </span>

        <span v-if="src" class="image-flyer__zoom" aria-hidden="true">
            <i class="bi bi-arrows-fullscreen"></i>
        </span>
    </button>

    <Teleport to="body">
        <div
            v-if="isOpen"
            class="image-flyer__backdrop"
            role="dialog"
            aria-modal="true"
            :aria-label="alt"
            @click.self="close"
        >
            <button
                type="button"
                class="image-flyer__close"
                aria-label="Cerrar"
                @click="close"
            >
                <i class="bi bi-x-lg"></i>
            </button>
            <img :src="src" :alt="alt" class="image-flyer__full">
        </div>
    </Teleport>
</template>

<style scoped>
.image-flyer {
    position: relative;
    display: block;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: zoom-in;
    /*
    | El wrapper es un <button>, no un <img>. Los callers le aplican width,
    | height y border-radius via thumb-class; el <img> interno ocupa el 100%
    | con object-fit: cover. overflow:hidden asegura que cuando el wrapper
    | tiene border-radius las esquinas del <img> se cortan y no sobresalen.
    */
    overflow: hidden;
}

.image-flyer:disabled {
    cursor: default;
}

.image-flyer__img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.25s;
    border-radius: inherit;
}

.image-flyer:not(:disabled):hover .image-flyer__img {
    transform: scale(1.04);
}

.image-flyer__placeholder {
    display: grid;
    place-items: center;
    width: 100%;
    height: 100%;
    color: var(--et-text-faint, #6b6b7d);
    background: var(--et-surface-hover, rgba(124, 92, 255, 0.08));
    border-radius: inherit;
}

.image-flyer__zoom {
    position: absolute;
    right: 6px;
    bottom: 6px;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: rgb(0 0 0 / 55%);
    color: #fff;
    font-size: 0.72rem;
    opacity: 0;
    transition: opacity 0.2s;
    pointer-events: none;
}

.image-flyer:hover .image-flyer__zoom,
.image-flyer:focus-visible .image-flyer__zoom {
    opacity: 1;
}

.image-flyer:focus-visible {
    outline: 2px solid var(--et-primary, #7C5CFF);
    outline-offset: 2px;
}

.image-flyer__backdrop {
    position: fixed;
    inset: 0;
    background: rgb(0 0 0 / 75%);
    -webkit-backdrop-filter: blur(2px);
    backdrop-filter: blur(2px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    z-index: 1090;
}

.image-flyer__full {
    display: block;
    max-width: 90vw;
    max-height: 90vh;
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: var(--et-radius, 8px);
    background: #fff;
    box-shadow: 0 20px 60px rgb(0 0 0 / 40%);
}

.image-flyer__close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 2px solid #fff;
    background: #333;
    color: #fff;
    cursor: pointer;
    display: grid;
    place-items: center;
    font-size: 1rem;
    box-shadow: 0 2px 8px rgb(0 0 0 / 40%);
    padding: 0;
}

.image-flyer__close:hover {
    background: #000;
}

/* Bloqueo global de scroll mientras hay un lightbox abierto. */
:global(body.flyer-open) {
    overflow: hidden;
}
</style>