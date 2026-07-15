<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'

const props = defineProps({
    profile: { type: Object, required: true },
})

const avatarForm = useForm({ file: null })
const avatarPreview = ref(null)

const profileForm = useForm({
    stage_name: props.profile.stage_name ?? '',
    bio: props.profile.bio ?? '',
})

// Trocar o nome regenera o slug: a URL pública muda e a antiga deixa de existir.
// Avisar antes de salvar, não depois — depois o link já quebrou.
const willRename = computed(
    () => profileForm.stage_name.trim() !== '' && profileForm.stage_name.trim() !== props.profile.stage_name,
)

const currentAvatar = computed(() => avatarPreview.value ?? props.profile.avatar_url)

function submitAvatar(event) {
    const file = event.target.files[0]
    if (!file) return

    avatarPreview.value = URL.createObjectURL(file)
    avatarForm.file = file
    avatarForm.post(route('performer.profile.photo'), {
        forceFormData: true,
        preserveScroll: true,
        onError: () => (avatarPreview.value = null),
    })
}

function save() {
    profileForm.post(route('performer.profile.save'), { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Editar perfil">
        <div class="max-w-2xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Editar perfil</h1>
                    <p class="text-muted text-sm">Seu nome, sua bio e sua foto — como o público te vê.</p>
                </div>
                <Link :href="route('performer.dashboard')" class="text-sm text-gold hover:text-gold-light transition-colors shrink-0">
                    Voltar ao painel
                </Link>
            </div>

            <!-- Avatar -->
            <div class="rounded-xl border border-frame bg-surface p-6 space-y-4">
                <h2 class="font-serif text-xl text-cream">Foto de perfil</h2>

                <div class="flex items-center gap-6">
                    <div class="h-24 w-24 rounded-full overflow-hidden bg-surface-2 border border-frame flex items-center justify-center shrink-0">
                        <img v-if="currentAvatar" :src="currentAvatar" alt="Sua foto de perfil" class="h-full w-full object-cover" />
                        <span v-else class="font-serif text-3xl text-gold">{{ profile.stage_name?.charAt(0) }}</span>
                    </div>

                    <div class="space-y-2">
                        <label class="cursor-pointer inline-block">
                            <span class="inline-flex items-center rounded-lg border border-gold text-gold px-4 py-2 text-sm hover:bg-gold/10 transition-colors">
                                {{ avatarForm.processing ? 'Enviando...' : 'Trocar foto' }}
                            </span>
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="hidden"
                                :disabled="avatarForm.processing"
                                @change="submitAvatar"
                            />
                        </label>
                        <p class="text-xs text-muted">JPG, PNG ou WebP. Até 5 MB.</p>
                        <p v-if="avatarForm.errors.file" class="text-xs text-danger">{{ avatarForm.errors.file }}</p>
                    </div>
                </div>
            </div>

            <!-- Name & bio -->
            <form class="rounded-xl border border-frame bg-surface p-6 space-y-5" @submit.prevent="save">
                <h2 class="font-serif text-xl text-cream">Nome e bio</h2>

                <Input
                    id="stage_name"
                    v-model="profileForm.stage_name"
                    label="Nome artístico"
                    required
                    :error="profileForm.errors.stage_name"
                />

                <div class="flex flex-col gap-1.5">
                    <label for="bio" class="text-sm font-medium text-cream">Bio</label>
                    <textarea
                        id="bio"
                        v-model="profileForm.bio"
                        rows="5"
                        maxlength="5000"
                        placeholder="Fale sobre você..."
                        class="rounded-lg border border-frame bg-surface-2 px-3 py-2 text-sm text-cream placeholder:text-muted focus:border-gold focus:outline-none"
                    />
                    <p v-if="profileForm.errors.bio" class="text-xs text-danger">{{ profileForm.errors.bio }}</p>
                </div>

                <!-- Public address -->
                <div class="rounded-lg border border-frame bg-surface-2 p-4 space-y-1">
                    <p class="text-xs text-muted uppercase tracking-wide">Endereço do seu perfil</p>
                    <p class="text-sm text-cream break-all">/performers/{{ profile.slug }}</p>
                </div>

                <div v-if="willRename" class="rounded-lg border border-gold/30 bg-gold/10 p-4 text-sm text-gold">
                    Trocar seu nome artístico muda o endereço do seu perfil. O endereço atual deixa de
                    funcionar, e quem tiver o link antigo não vai mais te encontrar por ele — vale
                    reenviar o novo para quem importa. Seus seguidores e interesses não se perdem.
                </div>

                <div class="flex justify-end">
                    <Button type="submit" variant="primary" :loading="profileForm.processing">
                        Salvar alterações
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
