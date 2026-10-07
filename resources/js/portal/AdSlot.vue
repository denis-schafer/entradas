<script setup>
/**
 * Zona de publicidad. Recibe la posicion y pide solo los anuncios vigentes de
 * esa posicion. Si no hay ninguno, no renderiza nada: un hueco vacio es mejor
 * que un contenedor de 0px.
 */
import { ref, onMounted, watch } from 'vue';
import api from '../api.js';

const props = defineProps({
    position: { type: String, required: true },
    eventId: { type: Number, default: null },
});

const ads = ref([]);

async function load() {
    try {
        const { data } = await api.get('tickets-portal/api/ads', {
            position: props.position,
            event_id: props.eventId ?? undefined,
        });

        ads.value = data;
    } catch {
        // La publicidad es opcional: si falla, el portal sigue igual.
        ads.value = [];
    }
}

onMounted(load);
watch(() => [props.position, props.eventId], load);
</script>

<template>
    <div v-if="ads.length" class="ad-slot">
        <a
            v-for="ad in ads"
            :key="ad.id"
            :href="ad.target_url || '#'"
            :target="ad.target_url ? '_blank' : undefined"
            rel="noopener"
            class="ad"
        >
            <img :src="ad.image_path" :alt="ad.name" loading="lazy">
        </a>
    </div>
</template>

<style scoped>
.ad {
    display: block;
    border-radius: var(--et-radius);
    overflow: hidden;
    border: 1px solid var(--et-border);
    transition: border-color var(--et-transition), transform var(--et-transition);
}

.ad:hover {
    border-color: var(--et-border-strong);
    transform: translateY(-2px);
}

.ad img {
    width: 100%;
    display: block;
}
</style>