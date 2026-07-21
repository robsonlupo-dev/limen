<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Button from '@/Components/Button.vue'
import Input from '@/Components/Input.vue'

const props = defineProps({
    enabled: { type: Boolean, default: false },
    pending: { type: Boolean, default: false },
    remainingRecoveryCodes: { type: Number, default: 0 },
    // Só vem no redirect imediatamente após ativar ou reemitir os códigos.
    // Não é estado da tela: some no próximo carregamento, de propósito.
    setup: { type: Object, default: null },
})

const enableForm = useForm({})
const confirmForm = useForm({ code: '' })
const disableForm = useForm({ code: '' })
const recoveryForm = useForm({ code: '' })

const showDisable = ref(false)
const showRecovery = ref(false)

const recoveryCodes = computed(() => props.setup?.recovery_codes ?? [])

function enable() {
    enableForm.post(route('performer.2fa.enable'), { preserveScroll: true })
}

function confirm() {
    confirmForm.post(route('performer.2fa.confirm'), {
        preserveScroll: true,
        onSuccess: () => confirmForm.reset(),
    })
}

function disable() {
    disableForm.post(route('performer.2fa.disable'), {
        preserveScroll: true,
        onSuccess: () => {
            disableForm.reset()
            showDisable.value = false
        },
    })
}

function regenerate() {
    recoveryForm.post(route('performer.2fa.recovery-codes'), {
        preserveScroll: true,
        onSuccess: () => {
            recoveryForm.reset()
            showRecovery.value = false
        },
    })
}

function copyCodes() {
    navigator.clipboard?.writeText(recoveryCodes.value.join('\n'))
}
</script>

<template>
    <AppLayout title="Verificação em duas etapas">
        <div class="max-w-2xl mx-auto px-6 py-10 space-y-8">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="space-y-1">
                    <h1 class="font-serif text-4xl text-cream">Verificação em duas etapas</h1>
                    <p class="text-muted text-sm">
                        Uma segunda camada além da senha. Protege seus documentos de verificação
                        e impede que alguém publique no seu lugar.
                    </p>
                </div>
                <Link :href="route('performer.dashboard')" class="text-sm text-gold hover:text-gold-light transition-colors shrink-0">
                    Voltar ao painel
                </Link>
            </div>

            <!-- ── Códigos de recuperação: mostrados UMA vez ────────────────── -->
            <div v-if="recoveryCodes.length" class="rounded-xl border border-gold/40 bg-surface p-6 space-y-4">
                <div class="space-y-1">
                    <p class="text-cream font-medium">Guarde seus códigos de recuperação</p>
                    <p class="text-muted text-sm">
                        Eles são a sua saída se você perder o celular. Cada um funciona
                        <strong class="text-cream">uma única vez</strong>. Esta é a única vez que
                        eles aparecem — anote agora, em um lugar seguro e fora do celular.
                    </p>
                </div>

                <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2 font-mono text-sm text-cream">
                    <li v-for="code in recoveryCodes" :key="code" class="rounded-lg bg-black/30 px-3 py-2">
                        {{ code }}
                    </li>
                </ul>

                <button type="button" class="text-sm text-gold hover:text-gold-light transition-colors" @click="copyCodes">
                    Copiar todos
                </button>
            </div>

            <!-- ── Estado 1: desligado ──────────────────────────────────────── -->
            <div v-if="!enabled && !pending" class="rounded-xl border border-frame bg-surface p-6 space-y-4">
                <div class="space-y-1">
                    <p class="text-cream font-medium">Ativar verificação em duas etapas</p>
                    <p class="text-muted text-sm">
                        Você vai precisar de um aplicativo autenticador no celular. Os mais comuns são
                        <strong class="text-cream">Google Authenticator</strong>,
                        <strong class="text-cream">Authy</strong> e
                        <strong class="text-cream">1Password</strong> — qualquer um que gere códigos
                        de 6 dígitos serve. Instale antes de continuar.
                    </p>
                </div>

                <Button :disabled="enableForm.processing" @click="enable">Ativar 2FA</Button>
            </div>

            <!-- ── Estado 2: segredo gerado, autenticador ainda não provado ─── -->
            <div v-if="pending" class="rounded-xl border border-frame bg-surface p-6 space-y-5">
                <div class="space-y-1">
                    <p class="text-cream font-medium">Escaneie o código</p>
                    <p class="text-muted text-sm">
                        Abra o app autenticador e escaneie a imagem abaixo. Depois digite o código de
                        6 dígitos que ele mostrar — é isso que confirma que deu certo.
                    </p>
                </div>

                <div v-if="setup?.qr_svg" class="flex justify-center">
                    <!-- SVG gerado no servidor (nunca por serviço externo de QR:
                         a otpauth:// carrega o segredo em claro). -->
                    <div class="bg-white p-3 rounded-xl" v-html="setup.qr_svg" />
                </div>

                <div v-if="setup?.secret" class="space-y-1">
                    <p class="text-muted text-xs">Não consegue escanear? Digite esta chave no app:</p>
                    <p class="font-mono text-sm text-cream break-all rounded-lg bg-black/30 px-3 py-2">
                        {{ setup.secret }}
                    </p>
                </div>

                <!-- Recarregou a tela e perdeu o QR: o segredo não é reexibido
                     (não fica em prop persistente). O caminho é gerar outro. -->
                <div v-if="!setup" class="rounded-lg border border-frame bg-black/20 p-4 space-y-3">
                    <p class="text-muted text-sm">
                        O código de configuração não é exibido de novo depois que você sai da tela.
                        Gere um novo para recomeçar — o anterior deixa de valer.
                    </p>
                    <Button :disabled="enableForm.processing" @click="enable">Gerar novo código</Button>
                </div>

                <form v-if="setup" class="space-y-3" @submit.prevent="confirm">
                    <Input
                        id="2fa-confirm-code"
                        v-model="confirmForm.code"
                        label="Código de 6 dígitos"
                        autocomplete="one-time-code"
                        placeholder="000000"
                        :error="confirmForm.errors.code"
                    />
                    <Button type="submit" :disabled="confirmForm.processing">Confirmar e ativar</Button>
                </form>
            </div>

            <!-- ── Estado 3: ativo ──────────────────────────────────────────── -->
            <div v-if="enabled" class="rounded-xl border border-frame bg-surface p-6 space-y-5">
                <div class="flex items-start gap-3">
                    <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-emerald-400" />
                    <div class="space-y-1">
                        <p class="text-cream font-medium">Verificação em duas etapas ativa</p>
                        <p class="text-muted text-sm">
                            Todo login neste navegador pede o código do seu autenticador.
                        </p>
                    </div>
                </div>

                <div class="rounded-lg bg-black/20 px-4 py-3 space-y-2">
                    <p class="text-sm text-cream">
                        Códigos de recuperação restantes:
                        <strong>{{ remainingRecoveryCodes }}</strong> de 8
                    </p>
                    <p v-if="remainingRecoveryCodes <= 2" class="text-danger text-sm">
                        Estão acabando. Gere um lote novo enquanto você ainda tem acesso.
                    </p>
                    <button type="button" class="text-sm text-gold hover:text-gold-light transition-colors" @click="showRecovery = !showRecovery">
                        Gerar novos códigos de recuperação
                    </button>

                    <form v-if="showRecovery" class="space-y-3 pt-2" @submit.prevent="regenerate">
                        <p class="text-muted text-xs">
                            Os códigos atuais param de funcionar. Confirme com o código do app.
                        </p>
                        <Input
                            id="2fa-recovery-code"
                            v-model="recoveryForm.code"
                            label="Código do autenticador"
                            autocomplete="one-time-code"
                            placeholder="000000"
                            :error="recoveryForm.errors.code"
                        />
                        <Button type="submit" :disabled="recoveryForm.processing">Gerar novos códigos</Button>
                    </form>
                </div>

                <div class="border-t border-frame pt-4 space-y-3">
                    <button type="button" class="text-sm text-danger hover:opacity-80 transition-opacity" @click="showDisable = !showDisable">
                        Desativar verificação em duas etapas
                    </button>

                    <form v-if="showDisable" class="space-y-3" @submit.prevent="disable">
                        <p class="text-muted text-sm">
                            Confirme com o código do app (ou um código de recuperação) para desativar.
                        </p>
                        <Input
                            id="2fa-disable-code"
                            v-model="disableForm.code"
                            label="Código do autenticador ou de recuperação"
                            autocomplete="one-time-code"
                            :error="disableForm.errors.code"
                        />
                        <Button type="submit" variant="danger" :disabled="disableForm.processing">
                            Desativar
                        </Button>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
