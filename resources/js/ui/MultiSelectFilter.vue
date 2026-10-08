<script setup>
/**
 * Selector multiple con filtro para listas largas (eventos asignados, etc).
 *
 * Sigue el patron del selector de servicios del panel de erden: el input
 * filtra mientras se escribe, clickear una opcion alterna su seleccion y
 * abajo quedan los elegidos en insignias removibles. No es un
 * <select multiple> nativo porque ese no deja buscar.
 */
import { ref, computed } from 'vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    modelValue: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Buscar...' },
    noneText: { type: String, default: 'Nada seleccionado' },
    emptyText: { type: String, default: 'Sin opciones que coincidan' },
});

const emit = defineEmits(['update:modelValue']);

const search = ref('');
const open = ref(false);

const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();

    if (!query) {
        return props.items;
    }

    return props.items.filter((item) => String(item.name).toLowerCase().includes(query));
});

const selected = computed(() =>
    props.modelValue.map((id) => props.items.find((item) => item.id === id)).filter(Boolean),
);

function isSelected(id) {
    return props.modelValue.includes(id);
}

function toggle(item) {
    emit(
        'update:modelValue',
        isSelected(item.id)
            ? props.modelValue.filter((id) => id !== item.id)
            : [...props.modelValue, item.id],
    );
}

function remove(id) {
    emit('update:modelValue', props.modelValue.filter((value) => value !== id));
}
</script>

<template>
    <div class="position-relative">
        <!--
            El Enter no debe mandar el form del modal: se corta aca. Los
            mousedown.prevent evitan que el blur cierre la lista antes de
            que corra el toggle de la opcion clickeada.
        -->
        <input
            v-model="search"
            type="search"
            class="form-control"
            :placeholder="placeholder"
            @focus="open = true"
            @blur="open = false"
            @keydown.enter.prevent
        >

        <div
            v-if="open && filtered.length"
            class="et-ms__list"
            @mousedown.prevent
        >
            <div
                v-for="item in filtered"
                :key="item.id"
                class="et-ms__option"
                :class="{ 'is-selected': isSelected(item.id) }"
                @mousedown.prevent="toggle(item)"
            >
                <span>{{ item.name }}</span>
                <i v-if="isSelected(item.id)" class="bi bi-check-lg"></i>
            </div>
        </div>

        <p v-else-if="open && !filtered.length" class="form-text mt-1 mb-0">
            {{ emptyText }}
        </p>
    </div>

    <div class="mt-2">
        <span v-for="item in selected" :key="item.id" class="et-badge et-badge--muted me-1 mb-1">
            {{ item.name }}
            <button type="button" class="et-ms__remove" @click="remove(item.id)">
                <i class="bi bi-x-lg"></i>
            </button>
        </span>
        <span v-if="!selected.length" class="form-text">{{ noneText }}</span>
    </div>
</template>

<style scoped>
.et-ms__list {
    position: absolute;
    left: 0;
    right: 0;
    top: calc(100% + 4px);
    z-index: 1050;
    max-height: 230px;
    overflow-y: auto;
    background: var(--et-bg-elevated);
    border: 1px solid var(--et-border-strong);
    border-radius: var(--et-radius-sm);
    box-shadow: var(--et-shadow-lg);
}

.et-ms__option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.55rem 0.8rem;
    font-size: 0.875rem;
    cursor: pointer;
    border-bottom: 1px solid var(--et-border);
}

.et-ms__option:last-child {
    border-bottom: 0;
}

.et-ms__option:hover {
    background: var(--et-surface-hover);
}

.et-ms__option.is-selected {
    color: var(--et-info);
    font-weight: 600;
}

.et-ms__remove {
    padding: 0;
    border: 0;
    background: none;
    color: inherit;
    line-height: 1;
    font-size: 0.65rem;
    cursor: pointer;
}
</style>
