<script setup>
defineProps({
    title: { type: String, default: 'Nada para mostrar' },
    hint: { type: String, default: '' },
    icon: { type: String, default: 'bi-inbox' },
});
</script>

<template>
    <div class="text-center py-5 px-3">
        <i :class="`bi ${icon} fs-1 text-faint`"></i>
        <p class="mt-3 mb-1 fw-semibold">{{ title }}</p>
        <p v-if="hint" class="text-muted-2 small mb-0">{{ hint }}</p>
        <slot />
    </div>
</template>