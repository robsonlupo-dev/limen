<script setup>
import { ref, onMounted } from 'vue'
import { setCookie } from '@/lib/cookies'

// GuestLayout only mounts this on a guest's first visit (no limen_intro_seen
// cookie, not logged in). We persist the "seen" flag as a 30-day cookie — not
// sessionStorage, which is per-tab and dies on browser close — and set it up
// front so a reload mid-intro never replays it.
const showing = ref(false)
const phase = ref(1)

onMounted(() => {
    setCookie('limen_intro_seen', '1', 30)
    showing.value = true

    // Reduced sequence on small screens (~1.4s) vs. full on desktop (~2.2s,
    // always under the 3s budget).
    const mobile = window.innerWidth < 768
    const t = mobile
        ? { text: 250, tagline: 600, end: 1400 }
        : { text: 400, tagline: 900, end: 2200 }

    setTimeout(() => { phase.value = 2 }, t.text)      // arch drawn → text
    setTimeout(() => { phase.value = 3 }, t.tagline)   // tagline
    setTimeout(() => { showing.value = false }, t.end) // fade out
})
</script>

<template>
    <Teleport to="body">
        <Transition name="intro-fade">
            <div v-if="showing" class="intro-overlay">
                <div class="intro-arch">
                    <svg viewBox="0 0 120 100" class="arch-svg">
                        <path
                            d="M 10 90 L 10 40 Q 10 10 60 10 Q 110 10 110 40 L 110 90"
                            fill="none"
                            stroke="#C9A84C"
                            stroke-width="2"
                            class="arch-path"
                        />
                    </svg>
                </div>

                <Transition name="text-appear">
                    <div v-if="phase >= 2" class="intro-text">LIMEN</div>
                </Transition>

                <Transition name="text-appear">
                    <div v-if="phase >= 3" class="intro-tagline">O Portal</div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.intro-overlay {
    position: fixed;
    inset: 0;
    z-index: 10000;
    background: #0a0a0a;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
}
.arch-svg {
    width: 120px;
    height: 100px;
}
.arch-path {
    stroke-dasharray: 280;
    stroke-dashoffset: 280;
    animation: draw-arch 0.6s ease forwards;
}
@keyframes draw-arch {
    to {
        stroke-dashoffset: 0;
    }
}
.intro-text {
    font-family: 'Cormorant Garamond', 'Georgia', serif;
    font-size: 36px;
    letter-spacing: 0.4em;
    color: #c9a84c;
    font-weight: 300;
}
.intro-tagline {
    font-size: 13px;
    letter-spacing: 0.25em;
    color: rgba(201, 168, 76, 0.6);
    text-transform: uppercase;
}
.intro-fade-leave-active {
    transition: opacity 0.5s ease;
}
.intro-fade-leave-to {
    opacity: 0;
}
.text-appear-enter-active {
    transition: opacity 0.4s ease, transform 0.4s ease;
}
.text-appear-enter-from {
    opacity: 0;
    transform: translateY(8px);
}
</style>
