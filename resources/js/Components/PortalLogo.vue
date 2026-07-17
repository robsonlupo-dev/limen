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
        <!--
            3D metallic gold arch, rebuilt from the brand reference.
            Geometry (viewBox 200×240): outer radius 80 / inner radius 40,
            centred at (100,100). Band thickness 40 per side → the two pillars
            together span ~40% of the width. Pillars run straight down to the
            base at y=210; light pools sit under each foot.
        -->
        <svg
            :width="size"
            :height="size * 1.2"
            viewBox="0 0 200 240"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
        >
            <defs>
                <!-- Cylinder shading across a pillar's own width (objectBoundingBox):
                     dark edge → light highlight → medium → dark edge. -->
                <linearGradient :id="`pillar-${uid}`" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0%" stop-color="#3D2800" />
                    <stop offset="33%" stop-color="#F5E098" />
                    <stop offset="66%" stop-color="#C9A24B" />
                    <stop offset="100%" stop-color="#3D2800" />
                </linearGradient>

                <!-- Curvature of the top: radial from the arch centre so the band
                     is dark at both edges (inner r=40, outer r=80) and bright in
                     the middle of its thickness. -->
                <radialGradient
                    :id="`crown-${uid}`"
                    gradientUnits="userSpaceOnUse"
                    cx="100" cy="100" r="80"
                >
                    <stop offset="50%" stop-color="#3D2800" />
                    <stop offset="66%" stop-color="#F5E098" />
                    <stop offset="83%" stop-color="#C9A24B" />
                    <stop offset="100%" stop-color="#3D2800" />
                </radialGradient>

                <!-- Golden light pooling on the floor under each foot. -->
                <radialGradient :id="`pool-${uid}`" cx="50%" cy="50%" r="50%">
                    <stop offset="0%" stop-color="#F5E098" stop-opacity="0.65" />
                    <stop offset="60%" stop-color="#C9A24B" stop-opacity="0.28" />
                    <stop offset="100%" stop-color="#C9A24B" stop-opacity="0" />
                </radialGradient>

                <!-- Soft metallic bloom around the whole arch. -->
                <filter :id="`glow-${uid}`" x="-25%" y="-25%" width="150%" height="150%">
                    <feGaussianBlur stdDeviation="3" result="b" />
                    <feMerge>
                        <feMergeNode in="b" />
                        <feMergeNode in="SourceGraphic" />
                    </feMerge>
                </filter>
            </defs>

            <!-- Reflection pools first, so the feet sit on top of them. -->
            <ellipse cx="40" cy="216" rx="36" ry="11" :fill="`url(#pool-${uid})`" />
            <ellipse cx="160" cy="216" rx="36" ry="11" :fill="`url(#pool-${uid})`" />

            <g :filter="`url(#glow-${uid})`">
                <!-- Top band (half annulus): outer semicircle over the top, then
                     the inner semicircle back, leaving the opening transparent. -->
                <path
                    d="M 20,100 A 80,80 0 0 1 180,100 L 140,100 A 40,40 0 0 0 60,100 Z"
                    :fill="`url(#crown-${uid})`"
                />

                <!-- Left pillar -->
                <path
                    d="M 20,100 L 60,100 L 60,210 L 20,210 Z"
                    :fill="`url(#pillar-${uid})`"
                />
                <!-- Right pillar (same objectBoundingBox gradient → shades on its
                     own width, keeping the light source consistent). -->
                <path
                    d="M 140,100 L 180,100 L 180,210 L 140,210 Z"
                    :fill="`url(#pillar-${uid})`"
                />

                <!-- Thin recessed line along the inner edge, echoing the reference. -->
                <path
                    d="M 60,210 L 60,100 A 40,40 0 0 1 140,100 L 140,210"
                    stroke="#F5E098"
                    stroke-width="1.4"
                    stroke-linecap="round"
                    fill="none"
                    opacity="0.35"
                />
            </g>
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
