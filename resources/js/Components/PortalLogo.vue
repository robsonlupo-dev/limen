<script setup>
import { computed } from 'vue'

defineProps({
    size: { type: Number, default: 64 },
    showText: { type: Boolean, default: true },
})

// Unique suffix so multiple instances on the same page don't collide on
// gradient/filter ids (e.g. header logo + in-page logo).
let counter = 0
const uid = computed(() => `portal-${(counter += 1)}-${Math.random().toString(36).slice(2, 8)}`)
</script>

<template>
    <div class="flex flex-col items-center gap-2">
        <svg
            :width="size"
            :height="size * 1.18"
            viewBox="0 0 100 118"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
        >
            <defs>
                <!-- Vertical gold gradient: dark → light → dark -->
                <linearGradient :id="`grad-${uid}`" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#C9A24B" />
                    <stop offset="50%" stop-color="#F0D080" />
                    <stop offset="100%" stop-color="#C9A24B" />
                </linearGradient>
                <!-- Soft golden glow simulating the 3D lighting -->
                <filter :id="`glow-${uid}`" x="-40%" y="-40%" width="180%" height="180%">
                    <feGaussianBlur stdDeviation="3.2" result="blur" />
                    <feMerge>
                        <feMergeNode in="blur" />
                        <feMergeNode in="SourceGraphic" />
                    </feMerge>
                </filter>
                <!-- Radial glow for the subtle light pooling at the feet -->
                <radialGradient :id="`feet-${uid}`" cx="50%" cy="50%" r="50%">
                    <stop offset="0%" stop-color="#F0D080" stop-opacity="0.55" />
                    <stop offset="100%" stop-color="#F0D080" stop-opacity="0" />
                </radialGradient>
            </defs>

            <!-- Blurred halo behind the arch -->
            <path
                d="M 13,109 L 13,48 A 37,37 0 0 1 87,48 L 87,109"
                :stroke="`url(#grad-${uid})`"
                stroke-width="13"
                stroke-linecap="round"
                fill="none"
                :filter="`url(#glow-${uid})`"
                opacity="0.5"
            />

            <!-- Subtle light pooling at the feet -->
            <ellipse cx="13" cy="112" rx="15" ry="6" :fill="`url(#feet-${uid})`" />
            <ellipse cx="87" cy="112" rx="15" ry="6" :fill="`url(#feet-${uid})`" />

            <!-- Main thick arch -->
            <path
                d="M 13,109 L 13,48 A 37,37 0 0 1 87,48 L 87,109"
                :stroke="`url(#grad-${uid})`"
                stroke-width="13"
                stroke-linecap="round"
                fill="none"
            />

            <!-- Thin inner arch line, echoing the recessed edge of the reference -->
            <path
                d="M 13,109 L 13,48 A 37,37 0 0 1 87,48 L 87,109"
                stroke="#F0D080"
                stroke-width="1.2"
                stroke-linecap="round"
                fill="none"
                opacity="0.4"
                transform="translate(0 1)"
            />
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
