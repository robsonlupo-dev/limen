<script setup>
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import PortalLogo from '@/Components/PortalLogo.vue'
import { postJson, deleteJson, errorMessage } from '@/lib/http'

const props = defineProps({
    waitlist: {
        type: Object,
        default: () => ({
            role: 'member',
            name: '',
            email_masked: '',
            confirmed: false,
            founder_title: '',
            invite_code: null,
            invite_link: null,
            referral_count: 0,
            whatsapp_opted_in: false,
            whatsapp_masked: null,
            auth_e: null,
            auth_k: null,
        }),
    },
})

// Token (id + HMAC) que identifica o registro nos próximos passos sem depender do
// cookie de sessão sobreviver ao redirect — enviado no corpo dos POSTs.
const auth = () => ({ e: props.waitlist.auth_e, k: props.waitlist.auth_k })

const w = computed(() => props.waitlist)
const isPerformer = computed(() => w.value.role === 'performer')
const confirmed = ref(w.value.confirmed)
// Esconde "Entrar / Criar conta" no pré-lançamento (igual à landing): o único
// caminho público é a lista de espera.
const prelaunch = computed(() => Boolean(usePage().props.features?.landing_prelaunch))

// ── Reenviar e-mail ───────────────────────────────────────────────────────────
const resending = ref(false)
const resent = ref(false)
async function resend() {
    resending.value = true
    try {
        await postJson(route('waitlist.continue.resend'), { ...auth() })
        resent.value = true
        setTimeout(() => { resent.value = false }, 4000)
    } catch { /* silencioso */ } finally {
        resending.value = false
    }
}
function openEmail() {
    const domain = (w.value.email_masked.split('@')[1] || '').toLowerCase()
    const map = {
        'gmail.com': 'https://mail.google.com',
        'outlook.com': 'https://outlook.live.com',
        'hotmail.com': 'https://outlook.live.com',
        'yahoo.com': 'https://mail.yahoo.com',
        'icloud.com': 'https://www.icloud.com/mail',
    }
    window.open(map[domain] || 'https://mail.google.com', '_blank', 'noopener')
}

// ── Convite ───────────────────────────────────────────────────────────────────
const link = computed(() => w.value.invite_link || '')
const copied = ref(false)
async function copyLink() {
    try {
        await navigator.clipboard.writeText(link.value)
        copied.value = true
        setTimeout(() => { copied.value = false }, 2200)
    } catch { /* contexto inseguro */ }
}

const tone = ref(0)
const tones = computed(() => isPerformer.value
    ? [
        { label: 'Direta', text: 'Entrei como criadora fundadora no Limen, uma plataforma brasileira +18 com verificação dos dois lados. Garanta sua vaga de fundadora:' },
        { label: 'Curiosa', text: 'Uma plataforma nova para criadoras vai abrir e eu já garanti minha vaga de fundadora. Só para maiores de 18. Quer entrar?' },
        { label: 'Discreta', text: 'Te mandei meu convite para uma plataforma privada que está abrindo, voltada a criadoras maiores de 18:' },
    ]
    : [
        { label: 'Direta', text: 'Entrei na lista de fundadores do Limen, uma plataforma brasileira +18 com verificação dos dois lados. Garanta sua vaga:' },
        { label: 'Curiosa', text: 'Uma novidade vai abrir em breve e eu já estou na lista de fundadores. Só para maiores de 18. Quer o convite?' },
        { label: 'Discreta', text: 'Te mandei meu convite para um clube privado que está abrindo. Reservado a maiores de 18:' },
    ])
const shareText = computed(() => tones.value[tone.value].text)
const shareFull = computed(() => `${shareText.value} ${link.value}`)

function openShare(url) { window.open(url, '_blank', 'noopener') }
function shareWhatsApp() { openShare(`https://wa.me/?text=${encodeURIComponent(shareFull.value)}`) }
function shareTelegram() { openShare(`https://t.me/share/url?url=${encodeURIComponent(link.value)}&text=${encodeURIComponent(shareText.value)}`) }
function shareX() { openShare(`https://twitter.com/intent/tweet?text=${encodeURIComponent(shareText.value)}&url=${encodeURIComponent(link.value)}`) }
function shareEmail() { openShare(`mailto:?subject=${encodeURIComponent('Seu convite para o Limen')}&body=${encodeURIComponent(shareFull.value)}`) }
function shareSms() { openShare(`sms:?&body=${encodeURIComponent(shareFull.value)}`) }
function shareInstagram() { copyLink() }
async function shareNative() {
    if (!navigator.share) { copyLink(); return }
    try { await navigator.share({ title: 'Limen', text: shareText.value, url: link.value }) } catch { /* cancelado */ }
}

// ── Opt-in de WhatsApp ────────────────────────────────────────────────────────
const phone = ref('')
const consent = ref(false)
const saving = ref(false)
const savedMasked = ref(w.value.whatsapp_masked)
const optedIn = ref(w.value.whatsapp_opted_in)
const wppError = ref('')

async function submitWhatsApp() {
    wppError.value = ''
    if (!consent.value) { wppError.value = 'Marque a caixa para aceitar receber avisos.'; return }
    saving.value = true
    try {
        const data = await postJson(route('waitlist.whatsapp.store'), { whatsapp: phone.value, consent: true, ...auth() })
        optedIn.value = true
        savedMasked.value = data.whatsapp_masked
    } catch (e) {
        wppError.value = errorMessage(e, 'Não foi possível salvar seu WhatsApp.')
    } finally {
        saving.value = false
    }
}
async function skipWhatsApp() {
    if (optedIn.value) {
        try { await deleteJson(route('waitlist.whatsapp.destroy'), { ...auth() }) } catch { /* ignora */ }
        optedIn.value = false
        savedMasked.value = null
    }
    phone.value = ''
    consent.value = false
}
</script>

<template>
    <GuestLayout title="Você está na lista — Limen" :hide-account-nav="prelaunch">
        <div class="mx-auto w-full max-w-md px-5 pb-16 text-limen-ink">
            <!-- TOPO -->
            <div class="flex items-center justify-between pt-6">
                <PortalLogo :size="40" />
                <div class="flex gap-1.5">
                    <span class="rounded-full bg-limen-gold px-2.5 py-1 text-xs font-bold tracking-wide text-limen-bg">
                        {{ isPerformer ? 'CRIADORA FUNDADORA' : 'MEMBRO FUNDADOR' }}
                    </span>
                    <span class="rounded-full border border-limen-line px-2.5 py-1 text-xs text-limen-ink-soft">+18</span>
                </div>
            </div>

            <!-- HERO -->
            <div class="relative mt-6 overflow-hidden rounded-2xl border border-limen-line" style="background: radial-gradient(120% 80% at 50% 115%, rgba(240,217,154,0.22), rgba(24,20,16,0) 60%), linear-gradient(180deg, #211b14, #181410);">
                <div class="flex flex-col items-center gap-3 px-6 pb-8 pt-10 text-center">
                    <svg width="56" height="56" viewBox="0 0 48 48" fill="none" aria-hidden="true">
                        <circle cx="24" cy="24" r="21" stroke="#d6b872" stroke-width="2.5" />
                        <path d="M15 24.5l6 6 12-13" stroke="#d6b872" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <div>
                        <div class="text-xs font-semibold tracking-[0.18em] text-limen-gold">{{ (w.founder_title || '').toUpperCase() }}</div>
                        <h1 class="mt-2 font-serif text-3xl leading-tight">
                            {{ w.name ? `Você está na lista, ${w.name}.` : 'Você está na lista.' }}
                        </h1>
                    </div>
                </div>
            </div>

            <!-- CONFIRME O E-MAIL -->
            <template v-if="!confirmed">
                <p class="mt-5 text-base text-limen-ink-soft">
                    Falta um passo: <strong class="text-limen-ink">confirme seu e-mail</strong>.
                    É o que garante sua vaga e o que faz você <strong class="text-limen-ink">receber o aviso quando o Limen abrir</strong> — sem confirmar, você pode perder o lançamento.
                </p>

                <div class="mt-4 flex items-center gap-2.5 rounded-xl border border-limen-line bg-limen-surface px-3.5 py-3">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3.5 7l8.5 6 8.5-6" /></svg>
                    <span class="text-sm text-limen-ink-soft">Enviamos um link para <strong class="text-limen-ink">{{ w.email_masked }}</strong></span>
                </div>

                <button type="button" class="mt-4 min-h-[52px] w-full rounded-xl bg-limen-gold text-base font-bold text-limen-bg" @click="openEmail">
                    Abrir meu e-mail
                </button>
                <div class="mt-3 flex items-center justify-between gap-3">
                    <button type="button" class="min-h-[44px] text-sm font-semibold text-limen-gold underline underline-offset-4 disabled:opacity-60" :disabled="resending" @click="resend">
                        {{ resending ? 'Enviando…' : (resent ? 'E-mail reenviado ✓' : 'Reenviar e-mail') }}
                    </button>
                </div>
                <p class="mt-1 text-sm text-limen-ink-mute">
                    Não chegou? Olhe em <strong class="text-limen-ink-soft">Spam</strong> ou
                    <strong class="text-limen-ink-soft">Promoções</strong> e marque como “não é spam” para não perder o aviso de lançamento.
                </p>
            </template>

            <template v-else>
                <div class="mt-5 flex items-center gap-2.5 rounded-xl border border-limen-gold bg-limen-surface px-3.5 py-3">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                    <span class="text-sm text-limen-ink">E-mail confirmado — sua vaga está garantida.</span>
                </div>
            </template>

            <!-- OPT-IN WHATSAPP -->
            <div class="mt-10 rounded-2xl border border-limen-line bg-limen-surface p-5">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-limen-surface-2">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#35c77a" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l1.3-4.2A8 8 0 1 1 8.4 18.8z" /><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1.2-1.4-2-1-1 .7a3.5 3.5 0 0 1-1.6-1.6l.7-1-1-2z" /></svg>
                    </div>
                    <span class="text-xs font-bold tracking-[0.14em] text-limen-gold">OPCIONAL</span>
                </div>

                <template v-if="optedIn">
                    <h2 class="mt-3 font-serif text-xl leading-snug">WhatsApp guardado ✓</h2>
                    <p class="mt-2 text-limen-ink-soft">Vamos te avisar em <strong class="text-limen-ink">{{ savedMasked }}</strong> quando as portas abrirem. Você pode sair quando quiser.</p>
                    <button type="button" class="mt-3 min-h-[44px] text-sm text-limen-ink-mute underline underline-offset-4" @click="skipWhatsApp">Remover meu WhatsApp</button>
                </template>

                <template v-else>
                    <h2 class="mt-3 font-serif text-xl leading-snug">Garanta o aviso de lançamento</h2>
                    <p class="mt-2 text-limen-ink-soft">
                        Deixe seu WhatsApp e a gente te chama quando o Limen abrir{{ isPerformer ? ' e nos próximos passos como fundadora' : '' }} — direto no seu celular, para não depender só do e-mail.
                    </p>

                    <label for="wpp" class="mt-3 block text-sm text-limen-ink-mute">Seu WhatsApp</label>
                    <input id="wpp" v-model="phone" type="tel" inputmode="tel" placeholder="(11) 91234-5678"
                        class="mt-1.5 min-h-[48px] w-full rounded-lg border border-limen-line bg-limen-bg px-3.5 text-base text-limen-ink" />

                    <label class="mt-3 flex cursor-pointer items-start gap-3 text-sm text-limen-ink-soft">
                        <input v-model="consent" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0" style="accent-color:#d6b872" />
                        <span>Aceito receber avisos do Limen por WhatsApp. Posso cancelar quando quiser respondendo SAIR.</span>
                    </label>

                    <p v-if="wppError" class="mt-2 text-sm text-limen-live">{{ wppError }}</p>

                    <button type="button" class="mt-3 min-h-[52px] w-full rounded-xl text-base font-bold disabled:opacity-70"
                        :class="consent ? 'bg-limen-gold text-limen-bg' : 'border border-limen-line bg-limen-surface-2 text-limen-ink-mute'"
                        :disabled="saving" @click="submitWhatsApp">
                        {{ saving ? 'Salvando…' : (isPerformer ? 'Quero ser avisada' : 'Quero ser avisado') }}
                    </button>
                </template>
            </div>

            <!-- CONVIDE AMIGOS -->
            <template v-if="link">
                <div class="mt-10 text-xs font-semibold tracking-[0.18em] text-limen-gold">CONVIDE E SUBA NA LISTA</div>
                <h2 class="mt-2 font-serif text-2xl leading-tight">
                    {{ isPerformer ? 'Chame outras criadoras' : 'Chame seus amigos' }}
                </h2>
                <p class="mt-2 text-limen-ink-soft">
                    Cada pessoa que entra pelo seu convite te faz subir na fila de fundadores — quem está na frente é chamado primeiro quando o Limen abrir.
                </p>

                <div class="mt-4 flex items-center gap-2 rounded-xl border border-limen-line bg-limen-surface py-1.5 pl-3.5 pr-1.5">
                    <span class="min-w-0 flex-1 truncate text-sm text-limen-ink">{{ link }}</span>
                    <button type="button" class="min-h-[44px] shrink-0 rounded-lg bg-limen-surface-2 px-4 text-sm font-bold text-limen-gold" @click="copyLink">{{ copied ? 'Copiado' : 'Copiar' }}</button>
                </div>

                <div class="mt-4">
                    <div class="mb-2 text-sm text-limen-ink-mute">Escolha o tom da mensagem</div>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="(t, i) in tones" :key="t.label" type="button" :aria-pressed="tone === i"
                            class="min-h-[44px] rounded-full px-4 text-sm font-semibold"
                            :class="tone === i ? 'bg-limen-gold text-limen-bg' : 'border border-limen-line text-limen-ink-soft'"
                            @click="tone = i">{{ t.label }}</button>
                    </div>
                </div>

                <div class="mt-4 max-w-[92%] rounded-2xl rounded-bl-sm px-3.5 py-3" style="background:#1c2b22">
                    <div class="text-sm" style="color:#e6efe9">{{ shareText }}</div>
                    <div class="mt-1.5 text-sm underline" style="color:#8fe0b3">{{ link }}</div>
                </div>

                <button type="button" class="mt-4 flex min-h-[54px] w-full items-center justify-center gap-2.5 rounded-xl text-base font-extrabold" style="background:#35c77a;color:#0b1f13" @click="shareWhatsApp">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0b1f13" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l1.3-4.2A8 8 0 1 1 8.4 18.8z" /><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1.2-1.4-2-1-1 .7a3.5 3.5 0 0 1-1.6-1.6l.7-1-1-2z" /></svg>
                    Enviar no WhatsApp
                </button>

                <div class="mt-2 grid grid-cols-3 gap-2">
                    <button type="button" class="flex min-h-[64px] flex-col items-center justify-center gap-1.5 rounded-xl border border-limen-line bg-limen-surface text-xs font-semibold text-limen-ink-soft" @click="shareTelegram">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 4L3 11l6 2.5L11.5 20l3-5 5 3z" /><path d="M9 13.5L21 4" /></svg>Telegram</button>
                    <button type="button" class="flex min-h-[64px] flex-col items-center justify-center gap-1.5 rounded-xl border border-limen-line bg-limen-surface text-xs font-semibold text-limen-ink-soft" @click="shareX">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4l14 16M19 4L5 20" /></svg>X</button>
                    <button type="button" class="flex min-h-[64px] flex-col items-center justify-center gap-1.5 rounded-xl border border-limen-line bg-limen-surface text-xs font-semibold text-limen-ink-soft" @click="shareInstagram">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="5" /><circle cx="12" cy="12" r="3.6" /><circle cx="17" cy="7" r="0.6" fill="#d6b872" /></svg>Instagram</button>
                    <button type="button" class="flex min-h-[64px] flex-col items-center justify-center gap-1.5 rounded-xl border border-limen-line bg-limen-surface text-xs font-semibold text-limen-ink-soft" @click="shareEmail">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3.5 7l8.5 6 8.5-6" /></svg>E-mail</button>
                    <button type="button" class="flex min-h-[64px] flex-col items-center justify-center gap-1.5 rounded-xl border border-limen-line bg-limen-surface text-xs font-semibold text-limen-ink-soft" @click="shareSms">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z" /></svg>SMS</button>
                    <button type="button" class="flex min-h-[64px] flex-col items-center justify-center gap-1.5 rounded-xl border border-limen-line bg-limen-surface text-xs font-semibold text-limen-ink-soft" @click="shareNative">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3M8 7l4-4 4 4" /><path d="M5 12v7h14v-7" /></svg>Mais opções</button>
                </div>
                <p v-if="w.referral_count > 0" class="mt-3 text-sm text-limen-ink-mute">
                    Você já trouxe <strong class="text-limen-gold">{{ w.referral_count }}</strong> {{ w.referral_count === 1 ? 'pessoa' : 'pessoas' }} para a lista.
                </p>
            </template>

            <!-- RODAPÉ -->
            <div class="mt-10 flex flex-col items-center gap-2 text-center">
                <PortalLogo :size="28" />
                <span class="text-xs text-limen-ink-mute">Conteúdo exclusivo para maiores de 18 anos.</span>
            </div>
        </div>
    </GuestLayout>
</template>
