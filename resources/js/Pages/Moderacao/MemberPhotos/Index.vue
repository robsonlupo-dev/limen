<script setup>
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import ModeratorLayout from '@/Layouts/ModeratorLayout.vue'
import Button from '@/Components/Button.vue'

/**
 * Fila de moderação das fotos de galeria de membro
 * (feat/member-gallery-and-profile).
 *
 * Feature sensível (rosto de usuário em site adulto). O moderador (ou admin) VÊ
 * cada foto pendente e aprova ou recusa (com motivo). O anti-CSAM automático já
 * rodou no upload; o humano é o gate contra foto de terceiro sem consentimento e
 * imagem não-consentida. Só `approved` vai ao ar no perfil/catálogo. Ao recusar,
 * os bytes são purgados na hora — a imagem some da fila.
 */
const props = defineProps({
    photos: { type: Object, required: true },
    pendingCount: { type: Number, required: true },
})

// Estado do formulário de recusa por item (motivo).
const rejecting = reactive({})

function toggleReject(id) {
    rejecting[id] = rejecting[id] === undefined ? '' : undefined
}

function approve(photo) {
    router.patch(route('moderacao.member-photos.update', photo.id), { status: 'approved' }, {
        preserveScroll: true,
    })
}

function reject(photo) {
    router.patch(route('moderacao.member-photos.update', photo.id), {
        status: 'rejected',
        reject_reason: rejecting[photo.id] ?? '',
    }, {
        preserveScroll: true,
    })
}
</script>

<template>
    <ModeratorLayout title="Moderação de fotos de membro">
        <div class="mx-auto max-w-4xl space-y-6 px-6 py-10">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-serif text-3xl text-cream">Fotos de membro</h1>
                    <p class="text-sm text-muted">{{ pendingCount }} aguardando análise.</p>
                </div>
                <Link :href="route('moderacao.reports.index')" class="text-sm text-gold/80 no-underline hover:text-gold">Denúncias &rarr;</Link>
            </div>

            <div v-if="photos.data.length === 0" class="rounded-xl border border-frame bg-surface p-10 text-center text-sm text-muted">
                Nada na fila.
            </div>

            <ul v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <li
                    v-for="photo in photos.data"
                    :key="photo.id"
                    class="space-y-4 rounded-xl border border-frame bg-surface p-5"
                >
                    <div class="flex items-center justify-between gap-4">
                        <p class="text-xs text-muted">{{ photo.member_ref }}</p>
                    </div>

                    <div class="overflow-hidden rounded-lg bg-background">
                        <img :src="photo.image_url" alt="Foto pendente de moderação" class="max-h-96 w-full object-contain" />
                    </div>

                    <div class="flex flex-wrap items-center gap-3 border-t border-frame pt-4">
                        <Button variant="primary" size="sm" @click="approve(photo)">Aprovar</Button>
                        <Button variant="ghost" size="sm" @click="toggleReject(photo.id)">Recusar</Button>
                    </div>

                    <div v-if="rejecting[photo.id] !== undefined" class="space-y-2">
                        <textarea
                            v-model="rejecting[photo.id]"
                            rows="2"
                            maxlength="500"
                            placeholder="Motivo da recusa (o membro verá)"
                            class="w-full rounded-lg border border-frame bg-background px-3 py-2 text-sm text-cream placeholder:text-muted focus:border-gold/50 focus:outline-none"
                        />
                        <Button variant="danger" size="sm" :disabled="!rejecting[photo.id]?.trim()" @click="reject(photo)">
                            Confirmar recusa
                        </Button>
                    </div>
                </li>
            </ul>
        </div>
    </ModeratorLayout>
</template>
