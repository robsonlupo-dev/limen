<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import LiveRoom from '@/Components/LiveRoom.vue'

defineProps({
    performerSlug: { type: String, required: true },
})
</script>

<template>
    <AppLayout title="Transmitir ao vivo">
        <div class="mx-auto max-w-4xl px-6 py-8 space-y-6">
            <div class="space-y-1">
                <h1 class="font-serif text-3xl text-cream">Transmitir ao vivo</h1>
                <p class="text-sm text-muted">Sua live pública aparece no catálogo com o selo “Ao vivo”.</p>
            </div>

            <LiveRoom :performer-slug="performerSlug" />
        </div>
    </AppLayout>
</template>
