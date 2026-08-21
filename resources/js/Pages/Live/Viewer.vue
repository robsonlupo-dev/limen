<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import LiveViewer from '@/Components/LiveViewer.vue'

const props = defineProps({
    performer: { type: Object, required: true },
    token: { type: String, required: true },
    wsUrl: { type: String, required: true },
    viewerCount: { type: Number, default: 0 },
    initialChat: { type: Array, default: () => [] },
})
</script>

<template>
    <AppLayout :title="`${performer.stage_name} · ao vivo`">
        <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6">
            <LiveViewer
                :performer="performer"
                :token="token"
                :ws-url="wsUrl"
                :viewer-count="viewerCount"
                :initial-chat="initialChat"
            />
        </div>
    </AppLayout>
</template>
