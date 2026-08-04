<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import LiveViewer from '@/Components/LiveViewer.vue'

const props = defineProps({
    performer: { type: Object, required: true },
    token: { type: String, required: true },
    wsUrl: { type: String, required: true },
    viewerCount: { type: Number, default: 0 },
})
</script>

<template>
    <AppLayout :title="`${performer.stage_name} · ao vivo`">
        <div class="mx-auto max-w-6xl px-6 py-8">
            <LiveViewer
                :performer="performer"
                :token="token"
                :ws-url="wsUrl"
                :viewer-count="viewerCount"
            />
        </div>
    </AppLayout>
</template>
