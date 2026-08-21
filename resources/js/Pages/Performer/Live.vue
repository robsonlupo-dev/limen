<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import LiveRoom from '@/Components/LiveRoom.vue'

defineProps({
    performerSlug: { type: String, required: true },
    initialChat: { type: Array, default: () => [] },
})
</script>

<template>
    <AppLayout title="Transmitir ao vivo">
        <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6">
            <div class="mb-4">
                <h1 class="font-serif text-2xl text-cream sm:text-3xl">Transmitir ao vivo</h1>
                <p class="text-sm text-muted">Acompanhe quem está assistindo, o que você ganhou e converse com a sala.</p>
            </div>

            <LiveRoom :performer-slug="performerSlug" :initial-chat="initialChat" />
        </div>
    </AppLayout>
</template>
