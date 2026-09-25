<script setup>
import { computed, ref } from 'vue'
import { useForm, Link, usePage } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import Input from '@/Components/Input.vue'
import Button from '@/Components/Button.vue'
import PortalLogo from '@/Components/PortalLogo.vue'
import Captcha from '@/Components/Captcha.vue'
import ImageCropper from '@/Components/ImageCropper.vue'
import PerformerOnboardingWizard from '@/Components/Onboarding/PerformerOnboardingWizard.vue'

const props = defineProps({
    tipo: { type: String, default: 'membro' },
    // Programa de indicação: código vindo do link (?ref=) ou do cookie, para
    // preencher o campo. Vazio quando o cadastro não veio de uma indicação.
    ref: { type: String, default: '' },
})

// Sprint 7: o cadastro de performer virou o wizard de 5 passos (passos 1–3
// acontecem aqui; 4–5 continuam em /performer/onboarding após o redirect do
// register.store). O formulário de membro segue como era.
const isPerformer = computed(() => props.tipo === 'performer')

const worlds = [
    { value: 'mulheres', label: 'Mulheres' },
    { value: 'homens', label: 'Homens' },
    { value: 'casais', label: 'Casais' },
    { value: 'trans', label: 'Trans' },
]

const form = useForm({
    tipo: props.tipo,
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    birthdate: '',
    cpf: '',
    accept_terms: false,
    lgpd_consent: false,
    preferred_world: '',
    // Apelido OPCIONAL (feat/member-nickname). Vazio = fica como "Fã #NNNN".
    // Validação rígida no servidor (RegisterWebRequest → MemberNicknameService).
    nickname: '',
    // Foto de perfil OPCIONAL (fix/member-photo-and-crop). null = pulou (o
    // caminho normal). Com um File, o Inertia manda multipart automaticamente e o
    // register.store roda o mesmo pipeline (sanitização + anti-CSAM) do perfil.
    avatar: null,
    // Código de indicação OPCIONAL (feat/referral-program). Prefill do ?ref=/cookie.
    codigo_indicacao: props.ref ?? '',
    captcha_token: '',
})

// Cropper 1:1 opcional no cadastro. Só ABRE ao escolher arquivo; o corte
// definitivo é server-side. Membro pode pular e completar depois no perfil.
const pendingAvatarFile = ref(null)
const avatarPreview = ref(null)

function pickAvatar(event) {
    const file = event.target.files[0]
    event.target.value = ''
    if (!file) return
    pendingAvatarFile.value = file
}

function onAvatarCropped(file) {
    pendingAvatarFile.value = null
    avatarPreview.value = URL.createObjectURL(file)
    form.avatar = file
}

function clearAvatar() {
    form.avatar = null
    avatarPreview.value = null
}

// Desligado (o padrão) o widget nem monta, e o servidor não exige o campo.
const captchaConfig = usePage().props.captcha ?? { enabled: false, provider: null, sitekey: null }
const captcha = ref(null)

function submit() {
    form.post(route('register.store'), {
        // Token de uso único: recusado o cadastro (e-mail já existe, senha
        // fraca), o token foi junto e queimou. Sem rearmar, a correção do
        // formulário esbarraria no captcha em vez de passar.
        onError: () => captcha.value?.reset(),
        // O CPF também sai do form após o submit: ele não é persistido no
        // servidor, então não faz sentido continuar vivo no state do cliente.
        onFinish: () => form.reset('password', 'password_confirmation', 'cpf'),
    })
}
</script>

<template>
    <GuestLayout :title="isPerformer ? 'Torne-se Performer' : 'Criar conta'">
        <PerformerOnboardingWizard v-if="isPerformer" phase="register" />

        <div v-else class="min-h-[80vh] flex items-center justify-center px-6 py-16">
            <div class="w-full max-w-md">
                <div class="flex justify-center mb-8">
                    <PortalLogo :size="48" />
                </div>

                <div class="bg-surface border border-frame rounded-2xl p-8">
                    <h1 class="font-serif text-2xl text-cream mb-1">Criar conta</h1>
                    <p class="text-muted text-sm mb-8">Junte-se ao portal verificado.</p>

                    <form @submit.prevent="submit" novalidate class="space-y-5">
                        <Input
                            id="name"
                            v-model="form.name"
                            label="Nome completo"
                            type="text"
                            placeholder="Seu nome"
                            autocomplete="name"
                            :required="true"
                            :error="form.errors.name"
                        />

                        <Input
                            id="email"
                            v-model="form.email"
                            label="E-mail"
                            type="email"
                            placeholder="voce@email.com"
                            autocomplete="email"
                            :required="true"
                            :error="form.errors.email"
                        />

                        <Input
                            id="password"
                            v-model="form.password"
                            label="Senha"
                            type="password"
                            placeholder="Mínimo 8 caracteres"
                            autocomplete="new-password"
                            :required="true"
                            :error="form.errors.password"
                        />

                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            label="Confirmar senha"
                            type="password"
                            placeholder="Repita a senha"
                            autocomplete="new-password"
                            :required="true"
                            :error="form.errors.password_confirmation"
                        />

                        <Input
                            id="birthdate"
                            v-model="form.birthdate"
                            label="Data de nascimento"
                            type="date"
                            :required="true"
                            :error="form.errors.birthdate"
                        />

                        <!-- Membro: CPF exigido pelo ECA Digital. Validado no
                             servidor e descartado — nunca é gravado. -->
                        <div>
                            <Input
                                id="cpf"
                                v-model="form.cpf"
                                label="CPF"
                                type="text"
                                placeholder="000.000.000-00"
                                autocomplete="off"
                                :required="true"
                                :error="form.errors.cpf"
                            />
                            <p v-if="!form.errors.cpf" class="mt-1 text-xs text-muted">
                                Usado só para confirmar sua maioridade. Não armazenamos seu CPF.
                            </p>
                        </div>

                        <!-- World preference (member) -->
                        <div>
                            <label class="text-sm font-medium text-cream">
                                Qual mundo você quer explorar?
                                <span class="text-gold ml-0.5">*</span>
                            </label>
                            <div class="mt-2 grid grid-cols-3 gap-2">
                                <button
                                    v-for="world in worlds"
                                    :key="world.value"
                                    type="button"
                                    class="rounded-lg border px-3 py-2 text-sm transition-colors"
                                    :class="[
                                        form.preferred_world === world.value
                                            ? 'border-gold text-gold bg-gold/10'
                                            : 'border-frame text-muted hover:border-gold/50',
                                    ]"
                                    @click="form.preferred_world = world.value"
                                >
                                    {{ world.label }}
                                </button>
                            </div>
                            <p v-if="form.errors.preferred_world" class="text-xs text-danger mt-1">{{ form.errors.preferred_world }}</p>
                        </div>

                        <!-- Apelido OPCIONAL (feat/member-nickname). Público: é como
                             as performers te chamam, e aparece no chat de uma live.
                             Vazio = fica como "Fã #NNNN". -->
                        <div>
                            <Input
                                id="nickname"
                                v-model="form.nickname"
                                label="Apelido (opcional)"
                                type="text"
                                maxlength="20"
                                placeholder="Como as performers vão te chamar"
                                :error="form.errors.nickname"
                            />
                            <p class="text-xs text-muted mt-1">
                                É público — as performers veem, e os outros membros veem no chat de uma live.
                                Você pode escolher ou trocar depois no seu perfil.
                            </p>
                        </div>

                        <!-- Foto de perfil OPCIONAL (fix/member-photo-and-crop).
                             Pode pular e completar depois no perfil. A foto aparece
                             para as performers no catálogo — dito aqui, não nos Termos. -->
                        <div>
                            <label class="text-sm font-medium text-cream">
                                Foto de perfil
                                <span class="text-muted font-normal">(opcional)</span>
                            </label>
                            <div class="mt-2 flex items-center gap-4">
                                <div class="h-16 w-16 shrink-0 rounded-full border border-frame bg-surface-2 overflow-hidden flex items-center justify-center">
                                    <img v-if="avatarPreview" :src="avatarPreview" alt="Prévia da sua foto" class="h-full w-full object-cover" />
                                    <svg v-else viewBox="0 0 24 24" fill="none" class="h-8 w-8 text-muted" aria-hidden="true">
                                        <circle cx="12" cy="8" r="4" fill="currentColor" opacity="0.5" />
                                        <path d="M4 20c0-4 4-6 8-6s8 2 8 6" fill="currentColor" opacity="0.5" />
                                    </svg>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label class="cursor-pointer inline-block">
                                        <span class="inline-flex min-h-[44px] items-center rounded-lg border border-gold text-gold px-4 py-2 text-sm hover:bg-gold/10 transition-colors">
                                            {{ avatarPreview ? 'Trocar foto' : 'Escolher foto' }}
                                        </span>
                                        <input
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            class="hidden"
                                            @change="pickAvatar"
                                        />
                                    </label>
                                    <button
                                        v-if="avatarPreview"
                                        type="button"
                                        class="min-h-[44px] text-left text-xs text-muted hover:text-danger transition-colors"
                                        @click="clearAvatar"
                                    >
                                        Remover
                                    </button>
                                </div>
                            </div>
                            <p class="text-xs text-muted mt-1">Aparece para as performers no catálogo. Você pode adicionar depois.</p>
                            <p v-if="form.errors.avatar" class="text-xs text-danger mt-1">{{ form.errors.avatar }}</p>
                        </div>

                        <ImageCropper
                            :file="pendingAvatarFile"
                            :aspect-ratio="1"
                            :output-width="512"
                            title="Enquadre sua foto de perfil"
                            hint="Arraste e ajuste o zoom. A foto fica quadrada no seu perfil."
                            @crop="onAvatarCropped"
                            @cancel="pendingAvatarFile = null"
                        />

                        <!-- Código de indicação OPCIONAL (feat/referral-program).
                             Prefill do link ?ref=; um código inválido não bloqueia o
                             cadastro (o servidor só ignora o vínculo). -->
                        <div>
                            <Input
                                id="codigo_indicacao"
                                v-model="form.codigo_indicacao"
                                label="Código de indicação (opcional)"
                                type="text"
                                maxlength="32"
                                placeholder="Ex.: LM-7F3K2"
                                :error="form.errors.codigo_indicacao"
                            />
                            <p class="text-xs text-muted mt-1">
                                Foi indicado por alguém? Informe o código para vocês dois ganharem tokens.
                            </p>
                        </div>

                        <!-- Checkboxes -->
                        <div class="space-y-3">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input
                                    v-model="form.accept_terms"
                                    type="checkbox"
                                    class="mt-0.5 h-4 w-4 rounded border-frame bg-surface accent-gold"
                                />
                                <span class="text-sm text-muted">
                                    Li e aceito os
                                    <a href="#" class="text-gold underline">termos de uso</a>
                                </span>
                            </label>
                            <p v-if="form.errors.accept_terms" class="text-xs text-danger ml-7">{{ form.errors.accept_terms }}</p>

                            <label class="flex items-start gap-3 cursor-pointer">
                                <input
                                    v-model="form.lgpd_consent"
                                    type="checkbox"
                                    class="mt-0.5 h-4 w-4 rounded border-frame bg-surface accent-gold"
                                />
                                <span class="text-sm text-muted">
                                    Consinto com o
                                    <a href="#" class="text-gold underline">tratamento de dados (LGPD)</a>
                                </span>
                            </label>
                            <p v-if="form.errors.lgpd_consent" class="text-xs text-danger ml-7">{{ form.errors.lgpd_consent }}</p>
                        </div>

                        <div v-if="captchaConfig.enabled">
                            <Captcha
                                ref="captcha"
                                :provider="captchaConfig.provider"
                                :sitekey="captchaConfig.sitekey"
                                v-model="form.captcha_token"
                            />
                            <p v-if="form.errors.captcha_token" class="pt-1 text-xs text-danger">
                                {{ form.errors.captcha_token }}
                            </p>
                        </div>

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            class="w-full"
                            :loading="form.processing"
                        >
                            Criar conta
                        </Button>
                    </form>

                    <p class="mt-6 text-center text-sm text-muted">
                        Já tem conta?
                        <Link :href="route('login')" class="text-gold hover:text-gold-light">
                            Entrar
                        </Link>
                    </p>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
