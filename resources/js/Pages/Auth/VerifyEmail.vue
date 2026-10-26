<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PortalLogo from '@/Components/PortalLogo.vue'
import TokenCoin from '@/Components/TokenCoin.vue'
import { postJson, deleteJson, errorMessage } from '@/lib/http'

const props = defineProps({
    // Montado por App\Support\SignupWelcomeData. Em cenários sem o prop (ex.: um
    // acesso direto antigo à rota), cai num default seguro que esconde os blocos
    // de bônus/indicação.
    welcome: {
        type: Object,
        default: () => ({
            role: 'member',
            referral_enabled: false,
            referral_code: null,
            referral_link: null,
            bonuses: {
                member_purchase: { referrer: 10, referred: 5 },
                performer_first_earning: { referrer: 40, referred: 25 },
            },
            counters: { sent: 0, confirmed: 0, pending_tokens: 0 },
            email_masked: '',
            email_verified: false,
            whatsapp_opted_in: false,
            whatsapp_masked: null,
        }),
    },
})

const page = usePage()
const w = computed(() => props.welcome)
const isPerformer = computed(() => w.value.role === 'performer')
const email = computed(() => w.value.email_masked || page.props.auth?.user?.email || '')

// ── E-mail ──────────────────────────────────────────────────────────────────
const resending = ref(false)
function resend() {
    resending.value = true
    router.post(route('verification.send'), {}, {
        preserveScroll: true,
        onFinish: () => { resending.value = false },
    })
}
function openEmail() {
    // Abre o provedor de e-mail mais provável a partir do domínio mascarado.
    const domain = (email.value.split('@')[1] || '').toLowerCase()
    const map = {
        'gmail.com': 'https://mail.google.com',
        'outlook.com': 'https://outlook.live.com',
        'hotmail.com': 'https://outlook.live.com',
        'yahoo.com': 'https://mail.yahoo.com',
        'icloud.com': 'https://www.icloud.com/mail',
    }
    window.open(map[domain] || 'https://mail.google.com', '_blank', 'noopener')
}

// ── Indicação / compartilhar ──────────────────────────────────────────────────
const link = computed(() => w.value.referral_link || '')
const copied = ref(false)
async function copyLink() {
    try {
        await navigator.clipboard.writeText(link.value)
        copied.value = true
        setTimeout(() => { copied.value = false }, 2200)
    } catch { /* contexto inseguro: a pessoa seleciona e copia à mão */ }
}

const tone = ref(0)
const tones = computed(() => isPerformer.value
    ? [
        { label: 'Direta', text: 'Estou entrando como criadora fundadora no Limen, uma plataforma brasileira +18 com verificação dos dois lados e saque por PIX. Quer o convite?' },
        { label: 'Curiosa', text: 'Está abrindo uma plataforma nova para criadoras e eu já garanti minha vaga. Só para maiores de 18. Quer o convite?' },
        { label: 'Discreta', text: 'Te mandei meu convite para uma plataforma privada que está abrindo, voltada a criadoras maiores de 18:' },
    ]
    : [
        { label: 'Direta', text: 'Entrei na lista de fundadores do Limen, uma plataforma brasileira +18 com verificação dos dois lados. Garanta sua vaga:' },
        { label: 'Curiosa', text: 'Uma novidade vai abrir em breve e eu já estou na lista de entrada. Só para maiores de 18. Quer o convite?' },
        { label: 'Discreta', text: 'Te mandei meu convite para um clube privado que está abrindo. Reservado a maiores de 18:' },
    ])
const shareText = computed(() => `${tones.value[tone.value].text}`)
const shareFull = computed(() => `${shareText.value} ${link.value}`)

function openShare(url) {
    window.open(url, '_blank', 'noopener')
}
function shareWhatsApp() {
    openShare(`https://wa.me/?text=${encodeURIComponent(shareFull.value)}`)
}
function shareTelegram() {
    openShare(`https://t.me/share/url?url=${encodeURIComponent(link.value)}&text=${encodeURIComponent(shareText.value)}`)
}
function shareX() {
    openShare(`https://twitter.com/intent/tweet?text=${encodeURIComponent(shareText.value)}&url=${encodeURIComponent(link.value)}`)
}
function shareEmail() {
    const subject = encodeURIComponent('Seu convite para o Limen')
    openShare(`mailto:?subject=${subject}&body=${encodeURIComponent(shareFull.value)}`)
}
function shareSms() {
    openShare(`sms:?&body=${encodeURIComponent(shareFull.value)}`)
}
function shareInstagram() {
    // Instagram não aceita texto por URL: copia e orienta a colar no direct/bio.
    copyLink()
}
async function shareNative() {
    if (!navigator.share) { copyLink(); return }
    try {
        await navigator.share({ title: 'Limen', text: shareText.value, url: link.value })
    } catch { /* cancelado */ }
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
        const data = await postJson(route('welcome.whatsapp.store'), { whatsapp: phone.value, consent: true })
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
        try { await deleteJson(route('welcome.whatsapp.destroy')) } catch { /* ignora */ }
        optedIn.value = false
        savedMasked.value = null
    }
    phone.value = ''
    consent.value = false
}
</script>

<template>
    <AppLayout title="Confirme seu e-mail">
        <div class="mx-auto w-full max-w-md px-5 pb-16 text-limen-ink">
            <!-- TOPO + PROGRESSO -->
            <div class="flex items-center justify-between pt-6">
                <PortalLogo :size="40" />
                <div class="flex gap-1.5">
                    <span class="rounded-full bg-limen-gold px-2.5 py-1 text-xs font-bold tracking-wide text-limen-bg">
                        {{ isPerformer ? 'CRIADORA' : 'MEMBRO' }}
                    </span>
                    <span class="rounded-full border border-limen-line px-2.5 py-1 text-xs text-limen-ink-soft">+18</span>
                </div>
            </div>

            <div class="mt-5 flex items-start" aria-label="Progresso do cadastro: passo 2 de 3">
                <div class="flex flex-1 flex-col items-center gap-1.5">
                    <div class="flex h-7 w-7 items-center justify-center rounded-full bg-limen-gold">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#181410" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                    </div>
                    <span class="text-xs text-limen-gold">Cadastro</span>
                </div>
                <div class="mt-3.5 h-0.5 flex-1 bg-limen-gold"></div>
                <div class="flex flex-1 flex-col items-center gap-1.5">
                    <div class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-limen-gold text-sm font-bold text-limen-gold">2</div>
                    <span class="text-xs font-semibold text-limen-ink">E-mail</span>
                </div>
                <div class="mt-3.5 h-0.5 flex-1 bg-limen-line"></div>
                <div class="flex flex-1 flex-col items-center gap-1.5">
                    <div class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-limen-line text-sm font-bold text-limen-ink-mute">3</div>
                    <span class="text-xs text-limen-ink-mute">WhatsApp</span>
                </div>
            </div>

            <!-- HERO -->
            <div class="relative mt-6 overflow-hidden rounded-2xl border border-limen-line" style="background: radial-gradient(120% 80% at 50% 115%, rgba(240,217,154,0.22), rgba(24,20,16,0) 60%), linear-gradient(180deg, #211b14, #181410);">
                <div class="flex flex-col items-center gap-3 px-6 pb-8 pt-10 text-center">
                    <svg width="56" height="74" viewBox="0 0 120 150" fill="none" aria-hidden="true">
                        <path d="M18 150 V54 Q18 14 60 14 Q102 14 102 54 V150" stroke="#d6b872" stroke-width="2.5" fill="none" />
                        <path d="M30 150 V58 Q30 26 60 26 Q90 26 90 58 V150 Z" fill="#161008" />
                        <path d="M60 26 Q90 26 90 58 V150 L60 150 Z" fill="#241d15" opacity="0.7" />
                        <circle cx="79" cy="92" r="2.4" fill="#f0d99a" />
                    </svg>
                    <div>
                        <div class="text-xs font-semibold tracking-[0.18em] text-limen-gold">
                            {{ isPerformer ? 'PRÉ-CADASTRO DE CRIADORA RECEBIDO' : 'PRÉ-CADASTRO DE MEMBRO RECEBIDO' }}
                        </div>
                        <h1 class="mt-2 font-serif text-3xl leading-tight">
                            {{ isPerformer ? 'Seu palco está quase pronto.' : 'A porta está entreaberta.' }}
                        </h1>
                    </div>
                </div>
            </div>

            <!-- CONFIRMAR E-MAIL -->
            <p class="mt-5 text-base text-limen-ink-soft">
                Falta um passo{{ isPerformer ? '' : ' para cruzar' }}:
                <strong class="text-limen-ink">confirme seu e-mail</strong>.
                Sem isso o cadastro não é concluído e seus bônus não valem.
            </p>

            <div class="mt-4 flex items-center gap-2.5 rounded-xl border border-limen-line bg-limen-surface px-3.5 py-3">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3.5 7l8.5 6 8.5-6" /></svg>
                <span class="text-sm text-limen-ink-soft">Enviamos um link para <strong class="text-limen-ink">{{ email }}</strong></span>
            </div>

            <button type="button" class="mt-4 min-h-[52px] w-full rounded-xl bg-limen-gold text-base font-bold text-limen-bg" @click="openEmail">
                Abrir meu e-mail
            </button>
            <div class="mt-3 flex items-center justify-between gap-3">
                <button type="button" class="min-h-[44px] text-sm font-semibold text-limen-gold underline underline-offset-4 disabled:opacity-60" :disabled="resending" @click="resend">
                    {{ resending ? 'Enviando…' : 'Reenviar e-mail' }}
                </button>
            </div>
            <p class="mt-1 text-sm text-limen-ink-mute">
                Não chegou? Olhe em <strong class="text-limen-ink-soft">Spam</strong> ou
                <strong class="text-limen-ink-soft">Promoções</strong> e marque como “não é spam”.
            </p>

            <!-- O QUE LIBERA -->
            <h2 class="mt-10 font-serif text-xl">Ao confirmar, você libera</h2>
            <div class="mt-4 space-y-3">
                <div class="flex gap-3.5 rounded-2xl border border-limen-line bg-limen-surface p-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-limen-surface-2">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z" /></svg>
                    </div>
                    <div>
                        <div class="font-bold">{{ isPerformer ? 'Sua vaga de Criadora Fundadora' : 'Sua vaga de Membro Fundador' }}</div>
                        <div class="mt-0.5 text-sm text-limen-ink-mute">Reservada em seu nome. Sem confirmar o e-mail, o cadastro não se conclui.</div>
                    </div>
                </div>
                <div class="flex gap-3.5 rounded-2xl border border-limen-line bg-limen-surface p-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-limen-surface-2">
                        <TokenCoin class="h-[22px] w-[22px] text-limen-gold" />
                    </div>
                    <div>
                        <div class="font-bold">Bônus de indicação em tokens</div>
                        <div class="mt-0.5 text-sm text-limen-ink-mute">
                            Seu link de convite fica ativo.
                            {{ isPerformer ? 'Convide outras criadoras e ganhe quando elas entrarem de verdade.' : 'Quem entra de verdade pela sua indicação rende tokens para você e para quem você chamou.' }}
                        </div>
                    </div>
                </div>
                <div class="flex gap-3.5 rounded-2xl border border-limen-line bg-limen-surface p-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-limen-surface-2">
                        <svg v-if="isPerformer" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6z" /><path d="M8.5 12l2.5 2.5 4.5-5" /></svg>
                        <svg v-else width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15z" /><path d="M10 21a2 2 0 0 0 4 0" /></svg>
                    </div>
                    <div>
                        <div class="font-bold">{{ isPerformer ? 'Passo a passo da sua verificação' : 'Aviso de lançamento em primeira mão' }}</div>
                        <div class="mt-0.5 text-sm text-limen-ink-mute">{{ isPerformer ? 'Identidade, idade e perfil: você é guiada até publicar o primeiro conteúdo.' : 'Fundadores são chamados antes da abertura geral.' }}</div>
                    </div>
                </div>
            </div>

            <!-- CRIADORA: COMO VOCÊ GANHA + PROTEÇÃO -->
            <template v-if="isPerformer">
                <h2 class="mt-11 font-serif text-2xl leading-tight">Você fica com a maior parte.</h2>
                <p class="mt-2 text-limen-ink-soft">Você define seus preços em tokens. Estas são as suas partes:</p>
                <div class="mt-4 grid grid-cols-2 gap-2.5">
                    <div v-for="s in [{p:'80%',t:'Conversas'},{p:'80%',t:'Conteúdo'},{p:'80%',t:'Gorjetas e presentes'},{p:'70%',t:'Chamadas e lives'}]" :key="s.t" class="rounded-2xl border border-limen-line bg-limen-surface p-4">
                        <div class="font-serif text-3xl leading-none text-limen-gold">{{ s.p }}</div>
                        <div class="mt-2 font-bold">{{ s.t }}</div>
                    </div>
                </div>
                <p class="mt-3 text-sm text-limen-ink-mute">Saque por PIX, a partir de 100 tokens por saque.</p>

                <h2 class="mt-10 font-serif text-xl">Seu conteúdo, protegido</h2>
                <div class="mt-3 space-y-3 text-sm">
                    <div class="flex gap-3"><span class="mt-0.5 text-limen-gold">◈</span><div><strong class="text-limen-ink">Marca de origem nas suas mídias.</strong> <span class="text-limen-ink-mute">Fotos e vídeos saem identificados, o que desencoraja vazamento.</span></div></div>
                    <div class="flex gap-3"><span class="mt-0.5 text-limen-gold">◈</span><div><strong class="text-limen-ink">Fãs verificados também.</strong> <span class="text-limen-ink-mute">Todo membro é maior de 18 e passa por verificação.</span></div></div>
                    <div class="flex gap-3"><span class="mt-0.5 text-limen-gold">◈</span><div><strong class="text-limen-ink">Botão de pânico.</strong> <span class="text-limen-ink-mute">Encerra sua sessão na hora, em um toque.</span></div></div>
                </div>
            </template>

            <!-- INDIQUE E GANHE -->
            <template v-if="w.referral_enabled">
                <div class="mt-11 text-xs font-semibold tracking-[0.18em] text-limen-gold">INDIQUE E GANHE</div>
                <h2 class="mt-2 font-serif text-2xl leading-tight">
                    {{ isPerformer ? 'Chame outra criadora. As duas ganham.' : 'Chame um amigo. Os dois ganham.' }}
                </h2>
                <p class="mt-2 text-limen-ink-soft">Sem cadastro fantasma: o bônus em tokens vem quando quem você indicou entra de verdade.</p>

                <div class="mt-4 rounded-2xl border border-limen-gold bg-limen-surface p-4">
                    <div class="flex items-center justify-between gap-2">
                        <div class="font-bold">{{ isPerformer ? 'Ela se verifica e recebe o 1º ganho' : 'Seu amigo vira membro e faz a 1ª compra' }}</div>
                        <span v-if="isPerformer" class="shrink-0 rounded-full bg-limen-gold px-2 py-0.5 text-[11px] font-bold tracking-wide text-limen-bg">MAIOR BÔNUS</span>
                    </div>
                    <div class="mt-3 flex gap-2.5">
                        <div class="flex-1 rounded-xl bg-limen-surface-2 p-3">
                            <div class="text-xs text-limen-ink-mute">Você ganha</div>
                            <div class="font-serif text-2xl text-limen-gold">+{{ isPerformer ? w.bonuses.performer_first_earning.referrer : w.bonuses.member_purchase.referrer }} <span class="font-sans text-xs text-limen-ink-soft">tokens</span></div>
                        </div>
                        <div class="flex-1 rounded-xl bg-limen-surface-2 p-3">
                            <div class="text-xs text-limen-ink-mute">{{ isPerformer ? 'Ela ganha' : 'Seu amigo ganha' }}</div>
                            <div class="font-serif text-2xl text-limen-gold">+{{ isPerformer ? w.bonuses.performer_first_earning.referred : w.bonuses.member_purchase.referred }} <span class="font-sans text-xs text-limen-ink-soft">tokens</span></div>
                        </div>
                    </div>
                    <div v-if="!isPerformer" class="mt-2 text-sm text-limen-ink-mute">{{ w.bonuses.member_purchase.referrer }} tokens pagam cerca de 5 conversas iniciadas com criadoras.</div>
                </div>

                <div class="mt-3 flex items-center justify-between gap-3 rounded-2xl border border-limen-line bg-limen-surface px-4 py-3.5">
                    <div class="text-sm text-limen-ink-soft">
                        <strong class="text-limen-ink">Bônus extra:</strong>
                        {{ isPerformer ? 'um fã que você chamar vira membro e faz a 1ª compra' : 'conhece uma criadora? Se ela se verificar e fizer o 1º ganho, você leva' }}
                    </div>
                    <div class="shrink-0 font-serif text-xl text-limen-gold">+{{ isPerformer ? w.bonuses.member_purchase.referrer : w.bonuses.performer_first_earning.referrer }}</div>
                </div>

                <!-- TRILHA -->
                <div class="mt-4 text-sm font-bold">Como acompanhar cada indicação</div>
                <div class="mt-2 flex items-start gap-1.5">
                    <div v-for="(st, i) in (isPerformer ? ['Cadastrou','Confirmou o e-mail','Verificada e 1º ganho','Bônus liberado'] : ['Cadastrou','Confirmou o e-mail','Fez a 1ª compra','Bônus liberado'])" :key="st" class="flex-1 text-center">
                        <div class="mx-auto mb-1.5 h-2.5 w-2.5 rounded-full" :class="i === 3 ? 'border-2 border-limen-gold' : 'bg-limen-gold'"></div>
                        <div class="text-xs" :class="i === 3 ? 'text-limen-gold' : 'text-limen-ink-soft'">{{ st }}</div>
                    </div>
                </div>

                <!-- CONTADORES -->
                <div class="mt-4 flex gap-2.5">
                    <div class="flex-1 rounded-xl border border-limen-line bg-limen-surface p-3 text-center">
                        <div class="font-serif text-2xl">{{ w.counters.sent }}</div>
                        <div class="text-xs text-limen-ink-mute">convites feitos</div>
                    </div>
                    <div class="flex-1 rounded-xl border border-limen-line bg-limen-surface p-3 text-center">
                        <div class="font-serif text-2xl">{{ w.counters.confirmed }}</div>
                        <div class="text-xs text-limen-ink-mute">confirmaram</div>
                    </div>
                    <div class="flex-1 rounded-xl border border-limen-line bg-limen-surface p-3 text-center">
                        <div class="font-serif text-2xl text-limen-gold">{{ w.counters.pending_tokens }}</div>
                        <div class="text-xs text-limen-ink-mute">tokens a receber</div>
                    </div>
                </div>

                <p class="mt-3 border-t border-limen-line pt-3 text-xs text-limen-ink-mute">
                    Bônus em tokens: crédito de uso dentro do Limen, <strong class="text-limen-ink-soft">não é dinheiro e não pode ser sacado</strong>.
                    Liberado após o lançamento e a conversão da indicação, com janela de segurança de 14 dias. Vale apenas com e-mail confirmado.
                </p>

                <!-- COMPARTILHAR -->
                <h2 class="mt-11 font-serif text-2xl leading-tight">{{ isPerformer ? 'Convide outras criadoras' : 'Seu convite pessoal' }}</h2>
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
            </template>

            <!-- OPT-IN WHATSAPP -->
            <div class="mt-11 rounded-2xl border border-limen-line bg-limen-surface p-5">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-limen-surface-2">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#35c77a" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l1.3-4.2A8 8 0 1 1 8.4 18.8z" /><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1.2-1.4-2-1-1 .7a3.5 3.5 0 0 1-1.6-1.6l.7-1-1-2z" /></svg>
                    </div>
                    <span class="text-xs font-bold tracking-[0.14em] text-limen-gold">PASSO 3 · OPCIONAL</span>
                </div>

                <template v-if="optedIn">
                    <h2 class="mt-3 font-serif text-xl leading-snug">WhatsApp guardado ✓</h2>
                    <p class="mt-2 text-limen-ink-soft">Vamos te avisar em <strong class="text-limen-ink">{{ savedMasked }}</strong> quando as portas abrirem. Você pode sair quando quiser.</p>
                    <button type="button" class="mt-3 min-h-[44px] text-sm text-limen-ink-mute underline underline-offset-4" @click="skipWhatsApp">Remover meu WhatsApp</button>
                </template>

                <template v-else>
                    <h2 class="mt-3 font-serif text-xl leading-snug">Não perca o lançamento</h2>
                    <p class="mt-2 text-limen-ink-soft">
                        Cadastre seu WhatsApp e a gente avisa quando as portas abrirem{{ isPerformer ? ' e te acompanha nos próximos passos da sua verificação' : ', e das novidades dos Fundadores' }}. Um aviso, direto no seu celular.
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
                    <button type="button" class="mt-1 min-h-[44px] w-full text-sm text-limen-ink-mute underline underline-offset-4" @click="skipWhatsApp">Agora não</button>
                </template>
            </div>

            <!-- RODAPÉ -->
            <div class="mt-10 flex flex-col items-center gap-2 text-center">
                <PortalLogo :size="28" />
                <span class="text-xs text-limen-ink-mute">Conteúdo exclusivo para maiores de 18 anos.</span>
            </div>
        </div>
    </AppLayout>
</template>
