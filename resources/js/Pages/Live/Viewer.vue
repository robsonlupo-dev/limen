<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import LiveViewer from '@/Components/LiveViewer.vue'

const props = defineProps({
    performer: { type: Object, required: true },
    token: { type: String, required: true },
    wsUrl: { type: String, required: true },
    viewerCount: { type: Number, default: 0 },
    initialChat: { type: Array, default: () => [] },
    paused: { type: Boolean, default: false },
    profileId: { type: Number, default: 0 },
    callPricePerMinute: { type: Number, default: null },
    myUserId: { type: Number, default: 0 },
    tokenPackages: { type: Array, default: () => [] },
    needsCpf: { type: Boolean, default: false },
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
                :paused="paused"
                :profile-id="profileId"
                :call-price-per-minute="callPricePerMinute"
                :my-user-id="myUserId"
                :token-packages="tokenPackages"
                :needs-cpf="needsCpf"
            />
        </div>
    </AppLayout>
</template>
