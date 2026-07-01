<script setup>
import { computed } from 'vue'

const props = defineProps({
    rating: { type: [Number, String], default: 0 },
    showValue: { type: Boolean, default: true },
})

const rounded = computed(() => Math.round(Number(props.rating) || 0))
const formatted = computed(() => (Number(props.rating) || 0).toFixed(1))
</script>

<template>
    <div class="inline-flex items-center gap-1.5">
        <span class="flex text-gold text-sm leading-none tracking-tight">
            <span v-for="n in 5" :key="n">{{ n <= rounded ? '★' : '☆' }}</span>
        </span>
        <span v-if="showValue" class="text-xs text-muted">{{ formatted }}</span>
    </div>
</template>
