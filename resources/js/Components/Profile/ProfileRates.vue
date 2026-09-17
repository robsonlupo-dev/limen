<script setup>
// Valores por minuto (feat/performer-profile-redesign, item 5). Sobem para perto
// das ações (coluna lateral no desktop, logo abaixo da voz no mobile) — deixaram
// de ser a última coisa da página. Cartões compactos lado a lado. Só layout: os
// números vêm do resource, nenhuma regra de preço muda.
defineProps({
    performer: { type: Object, required: true },
})

const RATES = [
    { key: 'rate_public', label: 'Público' },
    { key: 'rate_private', label: 'Privado' },
    { key: 'rate_camera', label: 'Câmera' },
]
</script>

<template>
    <section class="space-y-2">
        <h2 class="text-[11px] uppercase tracking-[0.24em] text-limen-ink-mute">Valores por minuto</h2>
        <div class="grid grid-cols-3 gap-2">
            <div
                v-for="rate in RATES"
                :key="rate.key"
                class="rounded-xl border border-limen-line bg-limen-surface px-2 py-3 text-center"
            >
                <p class="font-serif text-xl text-limen-gold sm:text-2xl">{{ performer[rate.key] }}</p>
                <p class="mt-0.5 text-[11px] text-limen-ink-mute">{{ rate.label }}</p>
            </div>
        </div>
        <p class="text-[11px] text-limen-ink-mute/70">tokens por minuto</p>
    </section>
</template>
