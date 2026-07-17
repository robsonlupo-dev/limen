<script setup>
defineProps({
    size: { type: Number, default: 64 },
    showText: { type: Boolean, default: true },
})
</script>

<template>
    <div class="flex flex-col items-center gap-2">
        <img
            :src="'/images/limen-icon.png'"
            :width="size"
            :height="size"
            alt="Limen"
            class="object-contain"
        />
        <span
            v-if="showText"
            class="font-serif tracking-[0.2em] text-gold uppercase"
            :style="{ fontSize: size * 0.22 + 'px' }"
        >
            Limen
        </span>
    </div>
</template>
