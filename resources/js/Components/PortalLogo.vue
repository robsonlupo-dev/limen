<script setup>
defineProps({
    size: { type: Number, default: 64 },
    showText: { type: Boolean, default: true },
})
</script>

<template>
    <div class="flex flex-col items-center gap-2">
        <svg
            :width="size"
            :height="size * 0.85"
            viewBox="0 0 80 68"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
        >
            <!-- Outer arch -->
            <path
                d="M 6,66 L 6,34 Q 6,6 40,6 Q 74,6 74,34 L 74,66"
                stroke="#C9A24B"
                stroke-width="2.5"
                stroke-linecap="round"
                fill="none"
            />
            <!-- Inner arch -->
            <path
                d="M 18,66 L 18,36 Q 18,18 40,18 Q 62,18 62,36 L 62,66"
                stroke="#C9A24B"
                stroke-width="1.5"
                stroke-linecap="round"
                fill="none"
                opacity="0.55"
            />
            <!-- Threshold line -->
            <line x1="6" y1="66" x2="74" y2="66" stroke="#C9A24B" stroke-width="2.5" stroke-linecap="round"/>
        </svg>
        <span
            v-if="showText"
            class="font-serif tracking-[0.2em] text-gold uppercase"
            :style="{ fontSize: size * 0.22 + 'px' }"
        >
            Limen
        </span>
    </div>
</template>
