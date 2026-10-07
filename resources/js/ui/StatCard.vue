<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    hint: { type: String, default: '' },
    icon: { type: String, default: 'bi-graph-up' },
    tone: { type: String, default: 'default' },
});

const TONES = {
    default: 'var(--et-text)',
    success: 'var(--et-success)',
    danger: 'var(--et-danger)',
    warning: 'var(--et-warning)',
    primary: 'var(--et-primary)',
};
</script>

<template>
    <div class="et-surface-raised p-3 h-100">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <div class="min-w-0">
                <div class="text-faint text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.06em">
                    {{ label }}
                </div>
                <div class="numeric fs-3 fw-bold mt-1" :style="{ color: TONES[tone] }">
                    {{ value }}
                </div>
                <div v-if="hint" class="text-faint small mt-1">{{ hint }}</div>
            </div>
            <i :class="`bi ${icon} fs-5 text-faint`"></i>
        </div>
    </div>
</template>

<style scoped>
.min-w-0 {
    min-width: 0;
}
</style>