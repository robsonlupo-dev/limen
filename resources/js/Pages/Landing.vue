<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import WorldIcon from '@/Components/WorldIcon.vue'

// ── Landing cinematográfica v2 (feat/landing-cinematografica-v2) ──────────────
// Porta do redesign de design-canvas para o código real. O site segue em
// PRÉ-LANÇAMENTO (flag `features.landing_prelaunch`, default true): o único
// caminho de conversão é a LISTA DE FUNDADORES (captura de e-mail com double
// opt-in), que mora na seção #fundadores / #lista-de-espera. O backend de
// /cadastro continua intacto e volta ao header no lançamento (só o .env muda).
//
// Estrutura: (1) HERO de 7 cenas em ciclo automático (barra de progresso
// clicável, pausar/retomar, contador 0N/07, copy rotativa; cada cena de vídeo só
// MONTA o <video> enquanto ativa, para sempre recomeçar do zero); (2) O Portal
// que se abre com a rolagem (seção sticky dirigida por scroll); (3) Só o que é
// real; (4) Destaques (carrossel de 5); (5) Por dentro (mockup + FAQ); (6)
// Recursos (3 pilares); (7) Círculos; (8) Lista de fundadores (porta entreaberta
// + wizard de lista de espera); (9) rodapé com a nota de imagens por IA.
//
// Mídia 100% SELF-HOST (public/landing/* + fontes em public/fonts/) — nenhum
// asset de terceiro, então ExternalAssetPolicyTest segue verde. Vídeos mudos,
// H.264, sem áudio. `prefers-reduced-motion`: sem autoplay, sem auto-avanço, sem
// Ken Burns — pôster estático e troca de cena só no clique.

// `referral` chega pelo /convite/{code} (ConviteController): alimenta o selo
// "convidado por X" e sugere o papel na lista de espera (atribuição é via sessão).
const props = defineProps({
    referral: { type: Object, default: null },
})

// Pré-lançamento (flag global `features.landing_prelaunch`, default true): a
// landing esconde os botões de conta do header (só o logo). No lançamento,
// `LANDING_PRELAUNCH=false` traz os botões — só o .env muda, sem rebuild.
const prelaunch = computed(() => Boolean(usePage().props.features?.landing_prelaunch))

const isDesktop = ref(false)
const motionOk = ref(true)

// ── HERO: 7 cenas em ciclo automático ────────────────────────────────────────
// Cross-fade por opacidade (dirigido por TEMPO, não por scroll). Durações por
// cena vindas do design. Cenas de vídeo montam o <video> só quando ativas.
const heroScenes = [
    { k: 0, type: 'video', src: '/landing/abertura.mp4', poster: '/landing/porta.webp', alt: 'A câmera atravessa uma porta entreaberta rumo a um portal de luz dourada.', bg: '#050506', pos: 'center' },
    { k: 1, type: 'image', srcD: '/landing/corredor.webp', srcM: '/landing/corredor-mobile.webp', alt: 'Um corredor de portas douradas desaparecendo na penumbra.', bg: '#0b0d10', pos: 'center' },
    { k: 2, type: 'video', src: '/landing/hero-03-silhueta-nevoa.mp4', poster: '/landing/hero-03-poster.webp', alt: 'Silhueta de uma pessoa entrando numa sala tomada por névoa.', bg: '#0d0a08', pos: '55% 35%', panel: true },
    { k: 3, type: 'video', src: '/landing/hero-04-carimbo-selo-vela.mp4', poster: '/landing/hero-04-poster.webp', alt: 'Um selo de cera carimba o convite sobre a madeira, ao lado de uma vela acesa.', bg: '#0c0904', pos: '62% 50%' },
    { k: 4, type: 'image', srcD: '/landing/mascara.webp', srcM: '/landing/mascara-mobile.webp', alt: 'Máscara veneziana preta com filigrana dourada.', bg: '#101a24', pos: 'center' },
    { k: 5, type: 'video', src: '/landing/hero-06-vela-apagando.mp4', poster: '/landing/hero-06-poster.webp', alt: 'Uma vela que se apaga e solta uma fina fumaça.', bg: '#000000', pos: 'center', panel: true },
    { k: 6, type: 'video', src: '/landing/hero-07-salao-mascarado.mp4', poster: '/landing/hero-07-poster.webp', alt: 'Um salão de clube privado com convidados mascarados.', bg: '#120e0b', pos: '42% 50%' },
]

// Copy rotativa por cena. AJUSTE de copy (ponto de atenção nº 1 do handoff): o
// PIX vale para TOKENS — os Círculos são assinatura por cartão (Asaas), então
// nada de "sem cartão" como promessa geral.
const heroLines = [
    'Um portal, não um catálogo. Curadoria em vez de rolagem infinita.',
    'Cada porta, uma criadora verificada. Você escolhe qual atravessar.',
    'Conteúdo exclusivo, direto de quem cria — sem intermediários.',
    'Cada criadora verificada por biometria. Nenhum perfil falso passa pela porta.',
    'Discrição total. Você escolhe como aparece, e quem vê.',
    'Tokens por PIX, em segundos. Sem complicação.',
    'Feito para quem valoriza o que é real.',
]
const heroDurations = [4.5, 4.5, 10.5, 7.5, 5.5, 9.5, 7.5]

const heroIndex = ref(0)
const heroPaused = ref(false)
let heroTimer = null

const heroCounter = computed(() => '0' + (heroIndex.value + 1))
const heroPauseLabel = computed(() => (heroPaused.value ? 'Retomar cenas' : 'Pausar cenas'))

function heroSchedule(k) {
    clearTimeout(heroTimer)
    if (!motionOk.value || heroPaused.value) return
    heroTimer = setTimeout(() => {
        if (heroPaused.value) return
        heroGo((k + 1) % heroScenes.length)
    }, heroDurations[k] * 1000)
}
function heroGo(k) {
    heroIndex.value = k
    heroSchedule(k)
}
function toggleHeroPause() {
    heroPaused.value = !heroPaused.value
    if (heroPaused.value) clearTimeout(heroTimer)
    else heroSchedule(heroIndex.value)
}

// Object-position da mídia da cena (mobile tem recorte próprio no design; aqui
// usamos um valor único por cena que lê bem nos dois). Painel vertical à direita
// (cenas 2 e 5) é tratado no CSS por `.hscene--panel` só no desktop.
function mediaStyle(s) {
    return { objectPosition: s.pos || 'center' }
}

// ── "O Portal se abre com a rolagem" — seção sticky dirigida por scroll ───────
// Reaproveita o mármore/portal do repo (portal.webp): a imagem CRESCE conforme
// a pessoa rola (sensação de atravessar), o texto do "Capítulo I" some e o
// "ATRAVESSE o limiar" entra e sai; no fim a cena escurece para a seguinte. Tudo
// derivado da POSIÇÃO (simétrico: rolar pra cima desfaz), num rAF único.
const portalEl = ref(null)
const portalStickyEl = ref(null)
const portalProgress = ref(0)
let portalRaf = null

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v))

const portalSpriteScale = computed(() => {
    const p = portalProgress.value
    if (p < 0.62) return 1
    return 1 + Math.pow((p - 0.62) / 0.38, 2) * 5
})
const portalText1Opacity = computed(() => clamp(1 - portalProgress.value / 0.12, 0, 1))
const portalText2Opacity = computed(() => {
    const p = portalProgress.value
    return Math.min(clamp((p - 0.4) / 0.12, 0, 1), clamp((0.8 - p) / 0.08, 0, 1))
})
const portalText2Shift = computed(() => ((1 - clamp((portalProgress.value - 0.4) / 0.12, 0, 1)) * 20).toFixed(1) + 'px')
const portalDark = computed(() => clamp((portalProgress.value - 0.84) / 0.14, 0, 1))
const portalBarHeight = computed(() => (clamp(portalProgress.value, 0, 1) * 100).toFixed(1) + '%')

function runPortalScroll() {
    portalRaf = null
    const sec = portalEl.value
    const sticky = portalStickyEl.value
    if (!sec || !sticky) return
    const span = sec.offsetHeight - sticky.offsetHeight
    const p = span > 0 ? clamp(-sec.getBoundingClientRect().top / span, 0, 1) : 0
    if (Math.abs(p - portalProgress.value) > 0.001) portalProgress.value = p
}
function onPortalScroll() {
    if (portalRaf === null) portalRaf = requestAnimationFrame(runPortalScroll)
}

// ── Destaques: carrossel de 5 cards ──────────────────────────────────────────
// AJUSTE de copy nº 1 no card do PIX (tokens, não "sem cartão" geral).
const cards = [
    { img: '/landing/destaque-1.webp', alt: 'Envelope lacrado com selo de cera sobre madeira.', lead: 'Exclusivo de verdade.', tail: 'Cada conteúdo é lacrado para quem assina.' },
    { img: '/landing/destaque-2.webp', alt: 'Selo dourado sobre veludo negro.', lead: 'Verificação em cada perfil.', tail: 'Biometria e documento antes do primeiro post.' },
    { img: '/landing/mascara.webp', alt: 'Máscara veneziana preta com filigrana dourada.', lead: 'Discrição total.', tail: 'Apelido, modo fantasma e controle de quem te vê.' },
    { img: '/landing/destaque-4.webp', alt: 'Lounge com poltronas de veludo negro e detalhes dourados.', lead: 'Um clube, não uma vitrine.', tail: 'Curadoria no lugar da rolagem infinita.' },
    { img: '/landing/destaque-5.webp', alt: 'Salão em arcadas douradas sob luz baixa.', lead: 'Tokens por PIX.', tail: 'Na hora, direto na carteira — sem complicação.' },
]
const cardIndex = ref(0)
const cardsPaused = ref(false)
const trackEl = ref(null)
const cardStep = ref(0)
let cardTimer = null

const trackStyle = computed(() => ({ transform: `translateX(-${cardIndex.value * cardStep.value}px)` }))

function measureCards() {
    const track = trackEl.value
    if (!track) return
    const first = track.querySelector('.d-card')
    if (!first) return
    const gap = isDesktop.value ? 34 : 13
    cardStep.value = first.offsetWidth + gap
}
function goCard(k) {
    cardIndex.value = (k + cards.length) % cards.length
}
function nextCard() { cardsPaused.value = true; goCard(cardIndex.value + 1) }
function prevCard() { cardsPaused.value = true; goCard(cardIndex.value - 1) }
function pickCard(k) { cardsPaused.value = true; goCard(k) }
function toggleCards() { cardsPaused.value = !cardsPaused.value }
const cardsPauseLabel = computed(() => (cardsPaused.value ? 'Retomar destaques' : 'Pausar destaques'))

// ── Por dentro: FAQ accordion + mockup de telefone ───────────────────────────
const accData = [
    ['Verificação', 'Toda criadora passa por biometria e documento antes de publicar. O processo é confidencial e não aparece no perfil.'],
    ['Tokens', 'Você compra tokens por PIX e usa para liberar conteúdo, assinar e dar gorjetas. Pacotes maiores rendem mais.'],
    ['Gorjetas', 'Sua gorjeta chega na hora e aparece em destaque para a criadora, com a sua mensagem.'],
    ['Privacidade', 'Modo fantasma, modo discreto, online invisível e apelido de fã. Criadoras veem seu apelido, nunca seu nome.'],
    ['PIX', 'QR na tela, confirmação em segundos e tokens na carteira. O PIX é o trilho dos tokens.'],
]
const accIndex = ref(0)

// ── Recursos: 3 pilares ──────────────────────────────────────────────────────
const BF = 'Black · Founders'
const pillars = [
    {
        title: 'Discrição', sub: 'Ninguém precisa saber.',
        icon: 'M3 3l18 18M10.6 5.1A9.9 9.9 0 0 1 12 5c5 0 9 4.5 10 7a13 13 0 0 1-3 4.2M6.6 6.6C4.4 8 2.8 10 2 12c1 2.5 5 7 10 7 1.8 0 3.4-.5 4.8-1.3M9.9 9.9a3 3 0 0 0 4.2 4.2',
        items: [
            { name: 'Modo Discreto', desc: 'Você some da lista de fãs que a criadora vê.', tier: BF },
            { name: 'Modo Fantasma', desc: 'Suas visitas a perfis não ficam registradas.', tier: BF },
            { name: 'Online invisível', desc: 'Ninguém vê quando você está conectado.', tier: BF },
            { name: 'Sem confirmação de leitura', desc: 'Leia no seu tempo, sem aviso de "visto".', tier: BF },
            { name: 'Apelido de fã', desc: 'Criadoras veem um pseudônimo. Nunca seu nome.', tier: null },
            { name: 'Botão de pânico', desc: 'Um toque e a tela some, com a sessão encerrada.', tier: null },
            { name: 'Exclusão real', desc: 'Apagou, sumiu. Sem cópia guardada, como manda a LGPD.', tier: null },
        ],
    },
    {
        title: 'Conexão', sub: 'Perto de quem você escolheu.',
        icon: 'M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-5.1A8 8 0 1 1 21 12z',
        items: [
            { name: 'Chat direto', desc: 'Converse com a criadora, sem intermediário.', tier: null },
            { name: 'Visualização única', desc: 'Mensagens que somem depois de vistas.', tier: null },
            { name: 'Lives e chamadas privadas', desc: 'Ao vivo, com agendamento de horário.', tier: null },
            { name: 'Canal da criadora', desc: 'Recados para os fãs, sem expor quem leu.', tier: null },
            { name: 'Stories VIP', desc: 'Bastidores por círculo, com enquetes e reações.', tier: null },
            { name: 'Voz da criadora', desc: 'Uma apresentação em áudio, antes do primeiro contato.', tier: null },
            { name: 'Gorjetas e presentes', desc: 'Seu gesto chega na hora, em destaque.', tier: null },
        ],
    },
    {
        title: 'Exclusividade', sub: 'Um clube, não uma vitrine.',
        icon: 'M5 21V9a7 7 0 0 1 14 0v12M9 21v-5a3 3 0 0 1 6 0v5',
        items: [
            { name: 'Fã-clube', desc: 'Assine sua criadora favorita em tokens, com nível VIP opcional, e libere o acervo exclusivo dela.', tier: null },
            { name: 'Verificação dos dois lados', desc: 'Criadoras e membros passam por documento e biometria.', tier: null },
            { name: 'Só pessoas reais', desc: 'Nenhum perfil ou conteúdo gerado por IA.', tier: null },
            { name: 'Círculos com vagas contadas', desc: 'Black tem 500 vagas. Founders, apenas 100.', tier: null },
            { name: 'A Chave do Portal', desc: 'Número permanente e marcos físicos para quem é Founders.', tier: 'Founders' },
            { name: 'Limen Mementos', desc: 'Presentes físicos entre criadora e membro, com sigilo.', tier: 'Founders' },
            { name: 'Coleção salva', desc: 'Guarde o que é seu. Só você vê.', tier: null },
            { name: 'Indique e ganhe', desc: 'Tokens para você e para quem você convidar.', tier: null },
        ],
    },
]

// ── Círculos (fonte canônica: docs/ECONOMIA.md) ──────────────────────────────
const circles = [
    { name: 'EXPLORADOR', price: 'R$ 89,90', tokens: '105', discount: '10%', seats: null, note: 'A primeira porta.', hi: false, gold: false },
    { name: 'INSIDER', price: 'R$ 189,90', tokens: '230', discount: '10%', seats: null, note: 'Para quem já escolheu.', hi: false, gold: false },
    { name: 'PRESTIGE', price: 'R$ 389,90', tokens: '490', discount: '15%', seats: null, note: 'Mais perto, mais vezes.', hi: false, gold: false },
    { name: 'BLACK', price: 'R$ 749,90', tokens: '1.000', discount: '20%', seats: '500 vagas', note: 'Discrição total liberada.', hi: false, gold: true },
    { name: 'FOUNDERS', price: 'R$ 1.490', tokens: '2.100', discount: '25%', seats: '100 vagas', note: 'Número seu, para sempre.', hi: true, gold: true },
]

const footerLinks = [
    { label: 'Como funciona', href: '#por-dentro' },
    { label: 'Para criadoras', href: '#recursos' },
    { label: 'Círculos', href: '#circulos' },
    { label: 'Lista de fundadores', href: '#fundadores' },
]

// ── Lista de espera (ÚNICO CTA) ──────────────────────────────────────────────
// Wizard de 2 passos: passo 1 comum (papel + e-mail + 18+), passo 2 ramifica por
// papel. Um único POST no fim; o servidor re-valida por papel.
const worlds = [
    { value: 'mulheres', label: 'Mulheres' },
    { value: 'homens', label: 'Homens' },
    { value: 'casais', label: 'Casais' },
    { value: 'trans', label: 'Trans' },
]

const submitted = ref(false)
const step = ref(1)

const form = useForm({
    name: '',
    email: '',
    role: props.referral?.suggestedRole ?? 'member',
    world: null,
    world_preferences: [],
    performer_kind: null,
    age_confirmed: false,
    website: '', // honeypot — must stay empty
})

function selectRole(role) {
    form.role = role
    if (role === 'performer') form.world_preferences = []
    else { form.world = null; form.performer_kind = null }
}

function scrollToForm(role) {
    if (role) selectRole(role)
    document.getElementById('lista-de-espera')?.scrollIntoView({ behavior: motionOk.value ? 'smooth' : 'auto' })
}

function toggleWorldPreference(value) {
    const next = new Set(form.world_preferences)
    next.has(value) ? next.delete(value) : next.add(value)
    form.world_preferences = [...next]
}

function pickPerformerWorld(value) {
    form.world = value
    if (value !== 'casais') form.performer_kind = null
}

function onSubmit() {
    if (step.value === 1) { step.value = 2; return }
    form
        .transform((data) => {
            const base = {
                name: data.name, email: data.email, role: data.role,
                age_confirmed: data.age_confirmed, website: data.website,
            }
            return data.role === 'performer'
                ? { ...base, world: data.world, performer_kind: data.performer_kind }
                : { ...base, world_preferences: data.world_preferences }
        })
        .post(route('waitlist.store'), {
            preserveScroll: true,
            onSuccess: () => { submitted.value = true; step.value = 1; form.reset() },
            onError: (errors) => {
                if (errors.email || errors.role || errors.age_confirmed) step.value = 1
            },
        })
}

onMounted(() => {
    isDesktop.value = window.matchMedia('(min-width: 768px) and (pointer: fine)').matches
    motionOk.value = !window.matchMedia('(prefers-reduced-motion: reduce)').matches

    measureCards()

    if (!motionOk.value) return

    // Hero auto-cycle.
    heroSchedule(0)

    // Carrossel de destaques (6,5s), pausável.
    cardTimer = setInterval(() => {
        if (!cardsPaused.value) goCard(cardIndex.value + 1)
    }, 6500)

    // Scroll do Portal.
    window.addEventListener('scroll', onPortalScroll, { passive: true })
    runPortalScroll()
})

onBeforeUnmount(() => {
    clearTimeout(heroTimer)
    clearInterval(cardTimer)
    window.removeEventListener('scroll', onPortalScroll)
    if (portalRaf !== null) cancelAnimationFrame(portalRaf)
})
</script>

<template>
    <GuestLayout title="Limen — O portal do desejo, verificado e real" :hide-account-nav="prelaunch">
        <div class="lc" :class="{ 'lc--static': !motionOk }">
            <!-- Selo de convite (só quando /convite/{code} atribui um referrer). -->
            <div v-if="referral" class="fixed inset-x-0 top-20 z-30 flex justify-center px-4">
                <div class="rounded-full border px-5 py-2 text-sm backdrop-blur lc-invite-banner">
                    Você foi convidado por <span class="lc-gold">{{ referral.name }}</span>
                </div>
            </div>

            <!-- ══ HERO · 7 cenas em ciclo automático ══ -->
            <section id="topo" class="hero">
                <div
                    v-for="s in heroScenes"
                    :key="s.k"
                    class="hscene"
                    :class="{ 'hscene--panel': s.panel }"
                    :style="{ opacity: heroIndex === s.k ? 1 : 0, background: s.bg }"
                    :aria-hidden="heroIndex === s.k ? 'false' : 'true'"
                >
                    <template v-if="s.type === 'video'">
                        <video
                            v-if="heroIndex === s.k && motionOk"
                            class="hmedia"
                            :src="s.src"
                            :poster="s.poster"
                            autoplay
                            muted
                            playsinline
                            preload="auto"
                            :aria-label="s.alt"
                            :style="mediaStyle(s)"
                        />
                        <img v-else class="hmedia" :src="s.poster" :alt="s.alt" :style="mediaStyle(s)" />
                    </template>
                    <template v-else>
                        <picture>
                            <source media="(max-width: 767px)" :srcset="s.srcM" type="image/webp" />
                            <img class="hmedia hmedia--drift" :src="s.srcD" :alt="s.alt" :style="mediaStyle(s)" />
                        </picture>
                    </template>
                </div>

                <!-- Véu de leitura (base + lateral esquerda). -->
                <div class="hero-veil" aria-hidden="true" />

                <!-- Barra de progresso: 7 segmentos clicáveis + contador + pausar. -->
                <div class="hero-progress">
                    <span class="hero-counter">{{ heroCounter }} / 07</span>
                    <div class="hero-bars">
                        <button
                            v-for="s in heroScenes"
                            :key="'bar-' + s.k"
                            type="button"
                            class="hero-bar"
                            :aria-label="'Ver cena ' + (s.k + 1)"
                            :aria-current="heroIndex === s.k ? 'true' : 'false'"
                            @click="heroGo(s.k)"
                        >
                            <span class="hero-bar-fill" :class="{ 'is-on': s.k <= heroIndex }" />
                        </button>
                    </div>
                    <button type="button" class="hero-pause" :aria-label="heroPauseLabel" @click="toggleHeroPause">
                        <svg v-if="!heroPaused" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M5 3v10M11 3v10" /></svg>
                        <svg v-else width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M5 3l8 5-8 5z" /></svg>
                    </button>
                </div>

                <!-- Conteúdo do hero. -->
                <div class="hero-content">
                    <div class="hero-main-col">
                        <div class="hero-kicker">
                            <span class="hero-18">18+</span>
                            <span>Portal adulto verificado · Brasil</span>
                        </div>
                        <div class="hero-wordmark">LIMEN</div>
                        <div class="hero-tag-row">
                            <span class="hero-rule" aria-hidden="true" />
                            <span class="hero-tagline">Atravesse o limiar.</span>
                        </div>
                        <p class="hero-line">{{ heroLines[heroIndex] }}</p>
                    </div>
                    <div class="hero-actions">
                        <button type="button" class="btn-gold" @click="scrollToForm()">Entrar na lista de fundadores</button>
                        <a href="#portal" class="hero-scroll" :class="{ nudge: motionOk }">
                            Role para atravessar
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6l5 5 5-5" /></svg>
                        </a>
                    </div>
                </div>
            </section>

            <!-- ══ O Portal se abre com a rolagem ══ -->
            <section id="portal" ref="portalEl" class="portal" :style="motionOk ? { height: isDesktop ? '3800px' : '3000px' } : {}">
                <div ref="portalStickyEl" class="portal-sticky">
                    <div class="portal-media-wrap" aria-hidden="true">
                        <picture>
                            <source media="(max-width: 767px)" :srcset="'/landing/portal-mobile.webp'" type="image/webp" />
                            <img class="portal-media" :src="'/landing/portal.webp'" alt="" :style="motionOk ? { transform: `scale(${portalSpriteScale})` } : {}" />
                        </picture>
                    </div>
                    <div class="portal-top-veil" aria-hidden="true" />
                    <div class="portal-rail" aria-hidden="true"><span class="portal-rail-fill" :style="{ height: portalBarHeight }" /></div>

                    <div class="portal-cap" :style="{ opacity: motionOk ? portalText1Opacity : 1 }">
                        <div class="eyebrow">Capítulo I · O limiar</div>
                        <div class="portal-h">Toda porta guarda um segredo.</div>
                    </div>

                    <div class="portal-cross" :style="{ opacity: motionOk ? portalText2Opacity : 0, transform: `translateY(${portalText2Shift})` }">
                        <div class="portal-cross-a">ATRAVESSE</div>
                        <div class="portal-cross-b">o limiar.</div>
                    </div>

                    <div class="portal-darken" aria-hidden="true" :style="{ opacity: motionOk ? portalDark : 0 }" />
                </div>
            </section>

            <!-- ══ Só o que é real ══ -->
            <section class="sect marble">
                <div class="marble-veil" aria-hidden="true" />
                <div class="wrap real-grid">
                    <div class="real-head">
                        <div class="eyebrow">Do outro lado</div>
                        <h2 class="h-serif real-title">Só o que é <em>real.</em></h2>
                        <p class="lead">Um portal de conteúdo adulto verificado, feito no Brasil, para quem valoriza discrição e autenticidade.</p>
                    </div>
                    <ul class="real-list">
                        <li><span class="rn">I</span><div><div class="rt">Criadoras verificadas</div><div class="rd">Biometria e documento em cada perfil. Nenhum fake passa pela porta.</div></div></li>
                        <li><span class="rn">II</span><div><div class="rt">Conteúdo exclusivo</div><div class="rd">Direto de quem cria, sem intermediários.</div></div></li>
                        <li><span class="rn">III</span><div><div class="rt">PIX e discrição</div><div class="rd">Tokens por PIX, em segundos. Você escolhe como aparece no portal, e quem vê.</div></div></li>
                    </ul>
                </div>
            </section>

            <!-- ══ Destaques — carrossel ══ -->
            <section id="destaques" class="sect dark destaques">
                <div class="wrap destaques-head">
                    <h2 class="h-serif">Conheça o <em>Portal.</em></h2>
                    <div class="eyebrow">Destaques</div>
                </div>
                <div class="destaques-rail">
                    <div ref="trackEl" class="destaques-track" :style="trackStyle">
                        <article v-for="(c, idx) in cards" :key="'card-' + idx" class="d-card">
                            <img class="d-card-img" :src="c.img" :alt="c.alt" loading="lazy" />
                            <div class="d-card-veil" aria-hidden="true" />
                            <div class="d-card-text"><strong>{{ c.lead }}</strong> <span>{{ c.tail }}</span></div>
                        </article>
                    </div>
                </div>
                <div class="destaques-ctrl">
                    <button type="button" class="round-btn" aria-label="Destaque anterior" @click="prevCard">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 3L5 8l5 5" /></svg>
                    </button>
                    <div class="dots">
                        <button
                            v-for="(c, idx) in cards"
                            :key="'dot-' + idx"
                            type="button"
                            class="dot"
                            :class="{ 'is-on': idx === cardIndex }"
                            :aria-label="'Ver destaque ' + (idx + 1)"
                            @click="pickCard(idx)"
                        />
                    </div>
                    <button type="button" class="round-btn" :aria-label="cardsPauseLabel" @click="toggleCards">
                        <svg v-if="!cardsPaused" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M5 3v10M11 3v10" /></svg>
                        <svg v-else width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M5 3l8 5-8 5z" /></svg>
                    </button>
                    <button type="button" class="round-btn" aria-label="Próximo destaque" @click="nextCard">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3l5 5-5 5" /></svg>
                    </button>
                </div>
            </section>

            <!-- ══ Por dentro — FAQ + mockup ══ -->
            <section id="por-dentro" class="sect por-dentro">
                <div class="wrap">
                    <div class="eyebrow">Por dentro do Portal</div>
                    <h2 class="h-serif">Veja de perto.</h2>
                    <div class="pd-grid">
                        <!-- Mockup de telefone -->
                        <div class="phone">
                            <div class="phone-screen">
                                <div class="phone-notch" aria-hidden="true" />
                                <!-- Tela 0 · Verificação -->
                                <div v-show="accIndex === 0" class="ps ps-center">
                                    <img class="ps-avatar" :src="'/landing/destaque-2.webp'" alt="" />
                                    <div class="ps-h">Verificação do Portal</div>
                                    <div class="ps-sub">Confidencial. Não aparece no seu perfil nem para membros.</div>
                                    <div class="ps-list">
                                        <div class="ps-row">✓ Mostre que você é real</div>
                                        <div class="ps-row">✓ Apareça em destaque no catálogo</div>
                                        <div class="ps-row">✓ Proteja seu nome e seu conteúdo</div>
                                    </div>
                                    <div class="ps-cta">Verificar agora →</div>
                                </div>
                                <!-- Tela 1 · Carteira -->
                                <div v-show="accIndex === 1" class="ps">
                                    <div class="ps-label">Sua carteira</div>
                                    <div class="ps-balance">150 <span>tokens</span></div>
                                    <div class="ps-sub">Recarregue quando quiser — por PIX.</div>
                                    <div class="ps-pkgs">
                                        <div class="ps-pkg"><span>Básico · 100</span><span class="lc-gold">R$ 9,90</span></div>
                                        <div class="ps-pkg is-on"><span>Médio · 300 <span class="ps-star">★ popular</span></span><span class="lc-gold">R$ 27,90</span></div>
                                        <div class="ps-pkg"><span>Grande · 600</span><span class="lc-gold">R$ 52,90</span></div>
                                        <div class="ps-pkg"><span>Baleia · 1500</span><span class="lc-gold">R$ 119,90</span></div>
                                    </div>
                                    <div class="ps-foot">Desconto extra para quem está num Círculo</div>
                                </div>
                                <!-- Tela 2 · Gorjeta -->
                                <div v-show="accIndex === 2" class="ps ps-center">
                                    <img class="ps-avatar ps-avatar--sm" :src="'/landing/destaque-5.webp'" alt="" />
                                    <div class="ps-name">Criadora verificada <span class="lc-gold">✓</span></div>
                                    <div class="ps-sub">Sua gorjeta aparece em destaque para ela.</div>
                                    <div class="ps-tips">
                                        <div class="ps-tip">10</div>
                                        <div class="ps-tip is-on">25</div>
                                        <div class="ps-tip">50</div>
                                    </div>
                                    <div class="ps-msg">Deixe uma mensagem…</div>
                                    <div class="ps-cta">Enviar 25 tokens</div>
                                </div>
                                <!-- Tela 3 · Privacidade -->
                                <div v-show="accIndex === 3" class="ps">
                                    <div class="ps-h ps-h--sm">Privacidade</div>
                                    <div class="ps-toggle"><span>Modo fantasma</span><span class="sw is-on" aria-hidden="true"><i /></span></div>
                                    <div class="ps-toggle"><span>Apelido de fã</span><span class="lc-gold">@noturno</span></div>
                                    <div class="ps-toggle"><span>Modo discreto</span><span class="sw is-on" aria-hidden="true"><i /></span></div>
                                    <div class="ps-toggle"><span>Confirmação de leitura</span><span class="sw" aria-hidden="true"><i /></span></div>
                                    <div class="ps-toggle"><span>Mostrar que estou online</span><span class="sw" aria-hidden="true"><i /></span></div>
                                    <div class="ps-foot ps-foot--left">Criadoras veem seu apelido, nunca seu nome.</div>
                                </div>
                                <!-- Tela 4 · PIX -->
                                <div v-show="accIndex === 4" class="ps ps-center">
                                    <div class="ps-label">Pagar com PIX</div>
                                    <div class="ps-price">R$ 39,90</div>
                                    <div class="ps-qr" aria-hidden="true"><div class="ps-qr-grid" /></div>
                                    <div class="ps-sub">Expira em <span class="lc-gold">4:32</span></div>
                                    <div class="ps-cta ps-cta--ghost">Copiar código PIX</div>
                                    <div class="ps-foot">QR ilustrativo · Os tokens caem assim que o PIX é confirmado.</div>
                                </div>
                            </div>
                        </div>
                        <!-- Accordion -->
                        <div class="acc">
                            <div
                                v-for="(a, idx) in accData"
                                :key="'acc-' + idx"
                                class="acc-item"
                                :class="{ 'is-open': accIndex === idx }"
                            >
                                <button type="button" class="acc-btn" :aria-expanded="accIndex === idx ? 'true' : 'false'" @click="accIndex = idx">
                                    <span class="acc-plus" :class="{ 'is-open': accIndex === idx }" aria-hidden="true">
                                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M6 1v10M1 6h10" /></svg>
                                    </span>
                                    <span>{{ a[0] }}</span>
                                </button>
                                <div v-show="accIndex === idx" class="acc-body">{{ a[1] }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ══ Recursos — 3 pilares ══ -->
            <section id="recursos" class="sect marble">
                <div class="marble-veil marble-veil--strong" aria-hidden="true" />
                <div class="wrap">
                    <div class="recursos-head">
                        <div>
                            <div class="eyebrow">O que existe do outro lado</div>
                            <h2 class="h-serif recursos-title">Privacidade não é recurso.<br><em>É o produto.</em></h2>
                        </div>
                        <p class="lead recursos-sub">Tudo abaixo já está construído e testado. Cada promessa aqui é técnica, não de marketing.</p>
                    </div>
                    <div class="pillars">
                        <div v-for="(pl, pi) in pillars" :key="'pl-' + pi" class="pillar">
                            <div class="pillar-head">
                                <span class="pillar-ic">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="pl.icon" /></svg>
                                </span>
                                <div>
                                    <div class="pillar-title">{{ pl.title }}</div>
                                    <div class="pillar-sub">{{ pl.sub }}</div>
                                </div>
                            </div>
                            <div v-for="(it, ii) in pl.items" :key="'it-' + pi + '-' + ii" class="pillar-item">
                                <div class="pi-row">
                                    <span class="pi-name">{{ it.name }}</span>
                                    <span v-if="it.tier" class="pi-tier">{{ it.tier }}</span>
                                </div>
                                <div class="pi-desc">{{ it.desc }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ══ Círculos ══ -->
            <section id="circulos" class="sect dark circulos">
                <div class="wrap circulos-head">
                    <div class="eyebrow">Os Círculos</div>
                    <h2 class="h-serif">Cada círculo, <em>uma porta a mais.</em></h2>
                    <p class="lead">Assinatura mensal com tokens incluídos e desconto em tudo. Os dois círculos mais altos têm vagas contadas.</p>
                </div>
                <div class="wrap circulos-grid">
                    <div v-for="(ci, idx) in circles" :key="'ci-' + idx" class="circle" :class="{ 'is-hi': ci.hi }">
                        <div class="circle-name" :class="{ 'lc-gold': ci.gold }">{{ ci.name }}</div>
                        <div class="circle-price">{{ ci.price }}<span>/mês</span></div>
                        <div class="circle-div" aria-hidden="true" />
                        <div class="circle-meta">{{ ci.tokens }} tokens por mês<br>{{ ci.discount }} de desconto</div>
                        <div v-if="ci.seats" class="circle-seats">{{ ci.seats }}</div>
                        <div class="circle-note">{{ ci.note }}</div>
                    </div>
                </div>
            </section>

            <!-- ══ Lista de fundadores (ÚNICO CTA) ══ -->
            <section id="fundadores" class="sect fundadores">
                <div class="wrap fund-grid">
                    <div class="fund-media">
                        <picture>
                            <source media="(max-width: 767px)" :srcset="'/landing/fundadores-mobile.webp'" type="image/webp" />
                            <img class="fund-img" :src="'/landing/fundadores.webp'" alt="Porta de madeira entreaberta em um corredor de mármore negro, com luz dourada vazando pela fresta." loading="lazy" />
                        </picture>
                    </div>

                    <div id="lista-de-espera" class="fund-card">
                        <template v-if="submitted">
                            <div class="fund-done">
                                <div class="fund-check lc-gold"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M8 12.5l2.6 2.6L16 9.5" /></svg></div>
                                <h2 class="h-serif fund-done-h">Pronto! Você está na lista</h2>
                                <p class="fund-done-p">
                                    Fique de olho no seu e-mail — e <strong>confira a caixa de spam</strong>. Marque nossa
                                    mensagem como <span class="lc-gold">“não é spam”</span> para não perder o aviso de lançamento.
                                </p>
                            </div>
                        </template>

                        <template v-else>
                            <div class="eyebrow">Lista de fundadores</div>
                            <h2 class="h-serif fund-h">O acesso antecipado não fica aberto <em>para sempre.</em></h2>
                            <p class="lead fund-p">Quem entra agora atravessa primeiro, com condições de fundador e o selo Fundador no perfil.</p>

                            <p class="fund-step">Passo {{ step }} de 2</p>

                            <form class="fund-form" novalidate @submit.prevent="onSubmit">
                                <!-- Passo 1 -->
                                <template v-if="step === 1">
                                    <div>
                                        <span class="f-label">Eu quero entrar como</span>
                                        <div class="role-toggle" role="group" aria-label="Eu sou">
                                            <button type="button" class="role-opt" :class="{ 'is-on': form.role === 'member' }" @click="selectRole('member')">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>Quero explorar
                                            </button>
                                            <button type="button" class="role-opt" :class="{ 'is-on': form.role === 'performer' }" @click="selectRole('performer')">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z" /></svg>Sou criadora
                                            </button>
                                        </div>
                                        <p v-if="form.errors.role" class="f-err">{{ form.errors.role }}</p>
                                    </div>

                                    <div>
                                        <label for="wl-email" class="f-label">E-mail</label>
                                        <input id="wl-email" v-model="form.email" type="email" autocomplete="email" placeholder="voce@email.com" class="f-input" />
                                        <p v-if="form.errors.email" class="f-err">{{ form.errors.email }}</p>
                                    </div>

                                    <div>
                                        <label class="f-check">
                                            <input v-model="form.age_confirmed" type="checkbox" />
                                            <span>Confirmo que tenho <strong>18 anos ou mais</strong> e concordo em receber o convite de lançamento por e-mail.</span>
                                        </label>
                                        <p v-if="form.errors.age_confirmed" class="f-err">{{ form.errors.age_confirmed }}</p>
                                    </div>

                                    <button type="submit" class="btn-gold btn-block">Continuar</button>
                                </template>

                                <!-- Passo 2 -->
                                <template v-else>
                                    <div>
                                        <label for="wl-name" class="f-label">{{ form.role === 'performer' ? 'Nome artístico' : 'Nome' }}</label>
                                        <input
                                            id="wl-name"
                                            v-model="form.name"
                                            type="text"
                                            :autocomplete="form.role === 'performer' ? 'off' : 'name'"
                                            :placeholder="form.role === 'performer' ? 'Seu nome artístico' : 'Como podemos te chamar'"
                                            class="f-input"
                                        />
                                        <p v-if="form.errors.name" class="f-err">{{ form.errors.name }}</p>
                                    </div>

                                    <div v-if="form.role === 'member'">
                                        <span class="f-label">Quais mundos te interessam? <span class="f-opt">(opcional)</span></span>
                                        <div class="world-grid">
                                            <button
                                                v-for="world in worlds"
                                                :key="world.value"
                                                type="button"
                                                class="world-opt"
                                                :class="{ 'is-on': form.world_preferences.includes(world.value) }"
                                                @click="toggleWorldPreference(world.value)"
                                            >
                                                <WorldIcon :world="world.value" class="mr-1.5" />{{ world.label }}
                                            </button>
                                        </div>
                                    </div>

                                    <div v-else>
                                        <span class="f-label">Qual mundo você representa?</span>
                                        <div class="world-grid">
                                            <button
                                                v-for="world in worlds"
                                                :key="world.value"
                                                type="button"
                                                class="world-opt"
                                                :class="{ 'is-on': form.world === world.value }"
                                                @click="pickPerformerWorld(world.value)"
                                            >
                                                <WorldIcon :world="world.value" class="mr-1.5" />{{ world.label }}
                                            </button>
                                        </div>
                                        <p v-if="form.errors.world" class="f-err">{{ form.errors.world }}</p>

                                        <div v-if="form.world === 'casais'" class="world-sub">
                                            <span class="f-label">No mundo Casais, você se cadastra como</span>
                                            <div class="world-grid">
                                                <button type="button" class="world-opt" :class="{ 'is-on': form.performer_kind === 'solo' }" @click="form.performer_kind = 'solo'">Solo</button>
                                                <button type="button" class="world-opt" :class="{ 'is-on': form.performer_kind === 'casal' }" @click="form.performer_kind = 'casal'">Casal</button>
                                            </div>
                                            <p v-if="form.errors.performer_kind" class="f-err">{{ form.errors.performer_kind }}</p>
                                        </div>
                                    </div>

                                    <div class="fund-actions">
                                        <button type="button" class="link-back" @click="step = 1">← Voltar</button>
                                        <button type="submit" class="btn-gold btn-flex" :disabled="form.processing">Entrar na lista de fundadores</button>
                                    </div>
                                </template>

                                <!-- Honeypot. -->
                                <div class="sr-only" aria-hidden="true">
                                    <label>Não preencha este campo<input v-model="form.website" type="text" tabindex="-1" autocomplete="off" /></label>
                                </div>
                            </form>

                            <p class="fund-note">18+ · Sem spam. Depois de entrar, confira a caixa de spam e marque nosso e-mail como “não é spam” para não perder o aviso de abertura.</p>
                        </template>
                    </div>
                </div>
            </section>

            <!-- ══ Rodapé da landing (banda de fechamento) ══ -->
            <footer class="lc-foot">
                <div class="lc-foot-bg" aria-hidden="true">
                    <picture>
                        <source media="(max-width: 767px)" :srcset="'/landing/fundo-mobile.webp'" type="image/webp" />
                        <img class="lc-foot-img" :src="'/landing/fundo.webp'" alt="" loading="lazy" />
                    </picture>
                    <div class="lc-foot-veil" />
                </div>
                <div class="wrap lc-foot-inner">
                    <div class="lc-foot-tag">A porta está aberta.</div>
                    <div class="lc-foot-mark">LIMEN</div>
                    <nav class="lc-foot-nav" aria-label="Navegação da landing">
                        <a v-for="l in footerLinks" :key="l.href" :href="l.href">{{ l.label }}</a>
                    </nav>
                    <!-- AJUSTE de copy (ponto de atenção nº 3): imagens/vídeos do topo são
                         arte de ambiente gerada por IA, sem pessoas reais. -->
                    <p class="lc-foot-ai">
                        Imagens ilustrativas, geradas por IA, de ambientação. Todas as criadoras do Limen são
                        pessoas reais e verificadas.
                    </p>
                    <p class="lc-foot-copy">© 2026 Limen · Plataforma adulta verificada · Proibido para menores de 18 anos</p>
                </div>
            </footer>
        </div>
    </GuestLayout>
</template>

<style scoped>
/* ── Paleta e tipografia da landing (superfície de marketing bespoke) ────────
   A landing tem a paleta cinematográfica própria do design-canvas (fundo quase
   preto, dourado mais vivo que o limen-gold do app). É escopada ao componente,
   então não mexe nos design tokens globais `limen-*`. Fontes self-host: Cinzel
   (display/versalete), Cormorant Garamond (serif) e Manrope (corpo). */
.lc {
    --bg: #050506;
    --ink: #f2e8d6;
    --gold: #f3c97e;
    --gold-soft: #ddd1bc;
    --muted: #cfc3ae;
    --faint: #8a8280;
    --on-gold: #1a1208;
    --disp: 'Cinzel', 'Cormorant Garamond', Georgia, serif;
    --serif: 'Cormorant Garamond', Georgia, serif;
    --body: 'Manrope', system-ui, sans-serif;

    background: var(--bg);
    color: var(--ink);
    font-family: var(--body);
    overflow-x: clip;
    line-height: 1.5;
}

.wrap {
    max-width: 1200px;
    margin-inline: auto;
    padding-inline: 1.3rem;
}
@media (min-width: 768px) {
    .wrap { padding-inline: 2.5rem; }
}

.lc-gold { color: var(--gold); }

.eyebrow {
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    color: var(--gold);
}

.h-serif {
    margin: 0;
    font-family: var(--serif);
    font-weight: 400;
    line-height: 1.02;
    color: var(--ink);
    font-size: clamp(2.6rem, 9vw, 4.5rem);
}
.h-serif em { font-style: italic; color: var(--gold); }

.lead {
    font-size: clamp(0.95rem, 2.4vw, 1.12rem);
    line-height: 1.55;
    color: var(--muted);
}

/* ── CTA dourado ─────────────────────────────────────────────────────────── */
.btn-gold {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 55px;
    padding: 0 1.9rem;
    border: none;
    border-radius: 999px;
    background: var(--gold);
    color: var(--on-gold);
    font-family: var(--body);
    font-size: 1rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: background-color 0.25s ease, transform 0.25s ease, box-shadow 0.25s ease;
}
.btn-gold:hover { background: #ffe0a3; transform: translateY(-2px); box-shadow: 0 12px 30px rgba(243, 201, 126, 0.28); }
.btn-gold:focus-visible { outline: 2px solid var(--gold); outline-offset: 3px; }
.btn-gold:disabled { opacity: 0.6; cursor: default; transform: none; box-shadow: none; }
.btn-block { width: 100%; }
.btn-flex { flex: 1; }

/* ── HERO ────────────────────────────────────────────────────────────────── */
.hero {
    position: relative;
    height: 100svh;
    min-height: 620px;
    overflow: hidden;
    background: #0c0a10;
}
.hscene {
    position: absolute;
    inset: 0;
    overflow: hidden;
    transition: opacity 1.2s ease;
}
.hmedia {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.hmedia--drift { animation: kb 16s ease-in-out infinite alternate; }

/* Painel vertical à direita no desktop (cenas silhueta/vela); full-bleed no mobile. */
@media (min-width: 768px) {
    .hscene--panel .hmedia {
        left: auto;
        right: 0;
        width: 46%;
        box-shadow: -40px 0 120px 40px rgba(5, 5, 6, 0.6);
    }
}

.hero-veil {
    position: absolute;
    inset: 0;
    z-index: 1;
    background:
        linear-gradient(to top, rgba(8, 6, 10, 0.94) 0%, rgba(8, 6, 10, 0.5) 38%, rgba(8, 6, 10, 0) 62%),
        linear-gradient(to right, rgba(8, 6, 10, 0.72) 0%, rgba(8, 6, 10, 0) 58%);
}

.hero-progress {
    position: absolute;
    z-index: 3;
    left: 1rem;
    right: 1rem;
    top: calc(env(safe-area-inset-top, 0px) + 0.9rem);
    display: flex;
    align-items: center;
    gap: 0.6rem;
}
.hero-counter {
    display: none;
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    color: var(--gold-soft);
    white-space: nowrap;
}
.hero-bars { flex-grow: 1; display: flex; gap: 5px; }
.hero-bar {
    flex-grow: 1;
    height: 22px;
    padding: 0;
    background: none;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
}
.hero-bar-fill {
    display: block;
    width: 100%;
    height: 2px;
    border-radius: 2px;
    background: rgba(242, 232, 214, 0.28);
    transition: background-color 0.4s ease;
}
.hero-bar-fill.is-on { background: var(--gold); }
.hero-pause {
    width: 44px;
    height: 44px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    color: var(--ink);
}

.hero-content {
    position: absolute;
    z-index: 2;
    left: 0;
    bottom: 0;
    width: 100%;
    box-sizing: border-box;
    padding: 0 1.3rem 2.2rem;
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
}
.hero-main-col { display: flex; flex-direction: column; gap: 0.8rem; }
.hero-kicker {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--gold);
}
.hero-18 {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 20px;
    padding: 0 7px;
    border: 1px solid rgba(243, 201, 126, 0.6);
    border-radius: 999px;
    letter-spacing: 0.06em;
}
.hero-wordmark {
    font-family: var(--disp);
    font-weight: 400;
    line-height: 0.92;
    letter-spacing: 0.14em;
    color: var(--ink);
    font-size: clamp(4.4rem, 20vw, 10.5rem);
    margin-right: -0.14em;
}
.hero-tag-row { display: flex; align-items: center; gap: 0.9rem; }
.hero-rule { width: 55px; height: 1px; background: var(--gold); flex-shrink: 0; }
.hero-tagline {
    font-family: var(--serif);
    font-style: italic;
    font-size: clamp(1.6rem, 5vw, 2.6rem);
    line-height: 1.1;
    color: var(--gold);
}
.hero-line {
    max-width: 560px;
    min-height: 2.6em;
    margin: 0;
    font-size: clamp(0.92rem, 2.6vw, 1.12rem);
    line-height: 1.45;
    color: var(--gold-soft);
}
.hero-actions { display: flex; flex-direction: column; gap: 0.5rem; padding-top: 0.4rem; }
.hero-scroll {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-height: 44px;
    font-size: 0.9rem;
    font-weight: 500;
    color: var(--ink);
    text-decoration: none;
}
.hero-scroll svg { color: var(--gold); }

@media (min-width: 768px) {
    .hero-progress { left: auto; right: 3.4rem; top: 6rem; width: 377px; }
    .hero-counter { display: block; }
    .hero-content {
        padding: 0 5.5rem 5.5rem;
        flex-direction: row;
        align-items: flex-end;
        gap: 3.4rem;
    }
    .hero-main-col { flex-grow: 1; gap: 1.1rem; }
    .hero-actions {
        flex-direction: column;
        width: 320px;
        flex-shrink: 0;
    }
}

/* ── O Portal se abre com a rolagem ──────────────────────────────────────── */
.portal { position: relative; background: var(--bg); }
.portal-sticky {
    position: sticky;
    top: 0;
    height: 100svh;
    overflow: hidden;
    background: var(--bg);
    display: flex;
}
.lc--static .portal-sticky { position: relative; height: auto; min-height: 70svh; padding: 20svh 0; }
.portal-media-wrap {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.portal-media {
    width: min(640px, 82vw);
    height: auto;
    max-height: 80svh;
    object-fit: contain;
    transform-origin: 50% 58%;
    will-change: transform;
}
.portal-top-veil {
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(5, 5, 6, 0.95) 0%, rgba(5, 5, 6, 0.4) 22%, rgba(5, 5, 6, 0) 40%);
}
.portal-rail {
    position: absolute;
    left: 1.3rem;
    top: 18%;
    width: 2px;
    height: 160px;
    background: rgba(242, 232, 214, 0.14);
}
.portal-rail-fill { display: block; width: 2px; background: var(--gold); }
.portal-cap,
.portal-cross {
    position: absolute;
    left: 0;
    bottom: 0;
    width: 100%;
    box-sizing: border-box;
    padding: 0 1.3rem 3.4rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.6rem;
    text-align: center;
}
.portal-h {
    font-family: var(--serif);
    font-size: clamp(2.3rem, 7vw, 3.25rem);
    line-height: 1.05;
    color: var(--ink);
}
.portal-cross { gap: 0.3rem; }
.portal-cross-a { font-family: var(--disp); font-size: clamp(2rem, 6vw, 3.5rem); letter-spacing: 0.18em; color: var(--ink); }
.portal-cross-b { font-family: var(--serif); font-style: italic; font-size: clamp(2rem, 6vw, 3.6rem); color: var(--gold); }
.portal-darken { position: absolute; inset: 0; background: var(--bg); }
@media (min-width: 768px) {
    .portal-rail { left: 5.5rem; top: 33%; height: 233px; }
    .portal-cap, .portal-cross { padding: 0 5.5rem 3.4rem; }
}

/* ── Seções genéricas ────────────────────────────────────────────────────── */
.sect { position: relative; padding: 5.5rem 0; }
@media (min-width: 768px) { .sect { padding: 9rem 0; } }
.dark { background: var(--bg); border-top: 1px solid rgba(243, 201, 126, 0.1); }
.marble { position: relative; background-image: url('/landing/fundo.webp'); background-size: cover; background-position: center; border-top: 1px solid rgba(243, 201, 126, 0.1); }
.marble-veil { position: absolute; inset: 0; background: linear-gradient(180deg, var(--bg) 0%, rgba(5, 5, 6, 0.86) 18%, rgba(5, 5, 6, 0.8) 100%); }
.marble-veil--strong { background: rgba(5, 5, 6, 0.9); }
.marble > .wrap { position: relative; z-index: 1; }

/* ── Só o que é real ─────────────────────────────────────────────────────── */
.real-grid { display: grid; gap: 2.2rem; }
.real-head { display: flex; flex-direction: column; gap: 1.1rem; }
.real-title { font-size: clamp(3rem, 11vw, 5.5rem); }
.real-list { list-style: none; margin: 0; padding: 0; }
.real-list li { display: flex; gap: 1.3rem; padding: 1.3rem 0; border-top: 1px solid rgba(243, 201, 126, 0.25); }
.real-list li:last-child { border-bottom: 1px solid rgba(243, 201, 126, 0.25); }
.rn { font-family: 'Cinzel', Georgia, serif; font-size: 1.05rem; color: var(--gold); width: 2rem; flex-shrink: 0; }
.rt { font-size: 1.2rem; font-weight: 600; color: var(--ink); margin-bottom: 0.3rem; }
.rd { font-size: 0.98rem; line-height: 1.55; color: var(--muted); }
@media (min-width: 768px) {
    .real-grid { grid-template-columns: 1fr 1fr; gap: 5.5rem; align-items: start; }
    .real-list { padding-top: 2rem; }
}

/* ── Destaques ───────────────────────────────────────────────────────────── */
.destaques { overflow: hidden; }
.destaques-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 1.5rem; margin-bottom: 2.4rem; }
.destaques-head .h-serif { font-size: clamp(2.6rem, 8vw, 4.5rem); }
.destaques-rail { padding-left: 1.3rem; overflow: hidden; }
.destaques-track { display: flex; gap: 13px; transition: transform 0.7s cubic-bezier(0.2, 0.7, 0.2, 1); }
.lc--static .destaques-track { transition: none; }
.d-card {
    position: relative;
    flex-shrink: 0;
    width: min(330px, 82vw);
    height: 440px;
    border-radius: 21px;
    overflow: hidden;
    background: #111;
}
.d-card-img { width: 100%; height: 100%; object-fit: cover; display: block; }
.d-card-veil { position: absolute; inset: 0; background: linear-gradient(to bottom, rgba(5, 5, 6, 0.82) 0%, rgba(5, 5, 6, 0) 50%); }
.d-card-text {
    position: absolute;
    left: 1.3rem;
    right: 1.3rem;
    top: 1.5rem;
    font-size: 1.15rem;
    font-weight: 600;
    line-height: 1.3;
    color: var(--ink);
}
.d-card-text span { color: var(--muted); font-weight: 500; }
.destaques-ctrl { display: flex; align-items: center; justify-content: center; gap: 0.8rem; margin-top: 1.5rem; }
.round-btn {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    border: 1px solid rgba(242, 232, 214, 0.3);
    background: none;
    color: var(--ink);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: border-color 0.2s ease, background-color 0.2s ease;
}
.round-btn:hover { border-color: var(--gold); background: rgba(243, 201, 126, 0.08); }
.dots { height: 44px; padding: 0 1.1rem; border-radius: 999px; background: rgba(242, 232, 214, 0.08); display: flex; align-items: center; gap: 8px; }
.dot { width: 8px; height: 8px; padding: 0; border: none; border-radius: 4px; background: rgba(242, 232, 214, 0.4); cursor: pointer; transition: width 0.3s ease, background-color 0.3s ease; }
.dot.is-on { width: 30px; background: var(--gold); }
@media (min-width: 768px) {
    .destaques-rail { padding-left: max(2.5rem, calc((100vw - 1200px) / 2 + 2.5rem)); }
    .destaques-track { gap: 34px; }
    .d-card { width: min(960px, 78vw); height: 540px; border-radius: 21px; }
    .d-card-text { left: 3.4rem; top: 3rem; max-width: 520px; font-size: 1.75rem; }
}

/* ── Por dentro ──────────────────────────────────────────────────────────── */
.por-dentro { background: #0b090d; border-top: 1px solid rgba(243, 201, 126, 0.1); }
.por-dentro .h-serif { font-size: clamp(2.6rem, 8vw, 4.5rem); margin-top: 0.4rem; }
.pd-grid { margin-top: 2.2rem; display: flex; flex-direction: column; gap: 2.2rem; align-items: center; }
@media (min-width: 768px) { .pd-grid { flex-direction: row-reverse; gap: 5.5rem; align-items: center; } }

.phone {
    width: 300px;
    height: 620px;
    flex-shrink: 0;
    border-radius: 44px;
    background: #16131a;
    box-shadow: 0 0 0 1px rgba(243, 201, 126, 0.35), 0 30px 90px rgba(0, 0, 0, 0.7);
    padding: 11px;
    box-sizing: border-box;
}
.phone-screen { position: relative; width: 100%; height: 100%; border-radius: 34px; background: #0c0a10; overflow: hidden; }
.phone-notch { position: absolute; left: 50%; top: 10px; width: 84px; height: 24px; margin-left: -42px; border-radius: 12px; background: #000; z-index: 2; }
.ps { position: absolute; inset: 0; padding: 60px 21px 21px; display: flex; flex-direction: column; gap: 13px; box-sizing: border-box; overflow-y: auto; }
.ps-center { align-items: center; text-align: center; }
.ps-avatar { width: 144px; height: 144px; border-radius: 50%; object-fit: cover; }
.ps-avatar--sm { width: 96px; height: 96px; box-shadow: 0 0 0 2px var(--gold); }
.ps-h { font-family: var(--serif); font-size: 1.75rem; color: var(--ink); }
.ps-h--sm { font-size: 1.6rem; }
.ps-name { font-size: 0.95rem; font-weight: 600; color: var(--ink); }
.ps-sub { font-size: 0.82rem; line-height: 1.5; color: var(--muted); }
.ps-label { font-size: 0.72rem; letter-spacing: 0.12em; text-transform: uppercase; color: var(--muted); }
.ps-balance { font-family: var(--serif); font-size: 3rem; line-height: 1; color: var(--gold); }
.ps-balance span { font-size: 1.2rem; color: var(--ink); }
.ps-price { font-family: var(--serif); font-size: 2.2rem; color: var(--gold); }
.ps-list { width: 100%; display: flex; flex-direction: column; gap: 8px; margin-top: 8px; text-align: left; }
.ps-row { padding: 12px; border-radius: 13px; background: rgba(242, 232, 214, 0.06); font-size: 0.8rem; color: var(--ink); }
.ps-pkgs { display: flex; flex-direction: column; gap: 8px; margin-top: 4px; }
.ps-pkg { padding: 12px; border-radius: 13px; background: rgba(242, 232, 214, 0.06); display: flex; justify-content: space-between; font-size: 0.8rem; }
.ps-pkg.is-on { background: rgba(243, 201, 126, 0.14); box-shadow: inset 0 0 0 1px var(--gold); }
.ps-star { font-size: 0.68rem; color: var(--gold); }
.ps-foot { font-size: 0.68rem; color: var(--faint); text-align: center; }
.ps-foot--left { text-align: left; color: var(--muted); line-height: 1.5; }
.ps-cta { width: 100%; min-height: 48px; margin-top: 4px; border-radius: 999px; background: var(--gold); color: var(--on-gold); font-size: 0.92rem; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.ps-cta--ghost { background: none; border: 1px solid rgba(243, 201, 126, 0.7); color: var(--gold); }
.ps-tips { display: flex; gap: 8px; margin-top: 4px; }
.ps-tip { width: 72px; height: 44px; border-radius: 13px; background: rgba(242, 232, 214, 0.06); display: flex; align-items: center; justify-content: center; font-size: 0.9rem; }
.ps-tip.is-on { background: rgba(243, 201, 126, 0.16); box-shadow: inset 0 0 0 1px var(--gold); color: var(--gold); }
.ps-msg { width: 100%; padding: 12px; box-sizing: border-box; border-radius: 13px; background: rgba(242, 232, 214, 0.06); font-size: 0.8rem; color: var(--faint); text-align: left; }
.ps-toggle { padding: 12px; border-radius: 13px; background: rgba(242, 232, 214, 0.06); display: flex; align-items: center; justify-content: space-between; font-size: 0.8rem; }
.sw { width: 40px; height: 24px; border-radius: 12px; background: rgba(242, 232, 214, 0.2); position: relative; flex-shrink: 0; }
.sw i { position: absolute; left: 3px; top: 3px; width: 18px; height: 18px; border-radius: 50%; background: var(--ink); }
.sw.is-on { background: var(--gold); }
.sw.is-on i { left: auto; right: 3px; background: var(--on-gold); }
.ps-qr { width: 170px; height: 170px; border-radius: 13px; background: var(--ink); padding: 12px; box-sizing: border-box; }
.ps-qr-grid { width: 100%; height: 100%; background: repeating-conic-gradient(#0c0a10 0% 25%, #f2e8d6 0% 50%) 0 0 / 22px 22px; }

.acc { width: 100%; display: flex; flex-direction: column; gap: 10px; }
@media (min-width: 768px) { .acc { width: 520px; flex-shrink: 0; } }
.acc-item { border-radius: 18px; background: rgba(242, 232, 214, 0.03); transition: background-color 0.3s ease, box-shadow 0.3s ease; }
.acc-item.is-open { background: rgba(242, 232, 214, 0.07); box-shadow: inset 0 0 0 1px rgba(243, 201, 126, 0.35); }
.acc-btn { width: 100%; min-height: 56px; padding: 0 1.1rem; display: flex; align-items: center; gap: 0.8rem; background: none; border: none; color: var(--ink); font-family: var(--body); font-size: 1.05rem; font-weight: 600; text-align: left; cursor: pointer; }
.acc-plus { width: 26px; height: 26px; border-radius: 50%; border: 1px solid rgba(243, 201, 126, 0.7); display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--gold); transition: transform 0.3s ease; }
.acc-plus.is-open { transform: rotate(45deg); }
.acc-body { padding: 0 1.1rem 1.1rem 3.6rem; font-size: 0.95rem; line-height: 1.6; color: var(--muted); }

/* ── Recursos ────────────────────────────────────────────────────────────── */
.recursos-head { display: flex; flex-direction: column; gap: 1.1rem; margin-bottom: 3rem; }
.recursos-title { font-size: clamp(2.6rem, 9vw, 5rem); }
.recursos-sub { max-width: 360px; }
.pillars { display: grid; gap: 3.4rem; }
.pillar { display: flex; flex-direction: column; }
.pillar-head { display: flex; align-items: center; gap: 0.8rem; padding-bottom: 1.1rem; }
.pillar-ic { width: 46px; height: 46px; border-radius: 23px 23px 4px 4px; border: 1px solid rgba(243, 201, 126, 0.6); display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--gold); }
.pillar-title { font-family: 'Cinzel', Georgia, serif; font-size: 1.3rem; letter-spacing: 0.16em; color: var(--ink); }
.pillar-sub { font-size: 0.8rem; color: var(--muted); }
.pillar-item { display: flex; flex-direction: column; gap: 4px; padding: 1rem 0; border-top: 1px solid rgba(243, 201, 126, 0.2); }
.pi-row { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
.pi-name { font-size: 1.02rem; font-weight: 600; color: var(--ink); }
.pi-tier { height: 20px; padding: 0 8px; border-radius: 999px; border: 1px solid rgba(243, 201, 126, 0.5); display: inline-flex; align-items: center; font-size: 0.6rem; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--gold); }
.pi-desc { font-size: 0.92rem; line-height: 1.5; color: var(--muted); }
@media (min-width: 768px) {
    .recursos-head { flex-direction: row; align-items: flex-end; justify-content: space-between; gap: 3.4rem; }
    .pillars { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 3.4rem; }
}

/* ── Círculos ────────────────────────────────────────────────────────────── */
.circulos-head { display: flex; flex-direction: column; gap: 1rem; text-align: left; margin-bottom: 2.2rem; }
.circulos-head .lead { max-width: 640px; }
.circulos-grid { display: flex; flex-direction: column; gap: 13px; }
.circle { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; padding: 1.1rem; border-radius: 18px; background: rgba(242, 232, 214, 0.04); box-shadow: inset 0 0 0 1px rgba(243, 201, 126, 0.22); text-align: left; }
.circle.is-hi { background: rgba(243, 201, 126, 0.1); box-shadow: inset 0 0 0 1px var(--gold); }
.circle-name { font-family: 'Cinzel', Georgia, serif; font-size: 1rem; letter-spacing: 0.16em; color: var(--ink); }
.circle-price { font-family: var(--serif); font-size: 1.7rem; line-height: 1; color: var(--ink); white-space: nowrap; }
.circle-price span { font-size: 0.85rem; color: var(--muted); }
.circle-div { display: none; }
.circle-meta { font-size: 0.85rem; line-height: 1.5; color: var(--muted); }
.circle-seats { height: 24px; padding: 0 12px; border-radius: 999px; background: rgba(243, 201, 126, 0.14); display: inline-flex; align-items: center; font-size: 0.62rem; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--gold); }
.circle-note { font-size: 0.82rem; line-height: 1.5; color: var(--faint); }
@media (min-width: 768px) {
    .circulos-head { align-items: center; text-align: center; margin-bottom: 4rem; }
    .circulos-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 21px; align-items: end; }
    .circle { align-items: center; text-align: center; gap: 13px; padding: 3.4rem 1.3rem 2.2rem; border-radius: 200px 200px 13px 13px; }
    .circle-price { font-size: 2.5rem; }
    .circle-div { display: block; width: 34px; height: 1px; background: rgba(243, 201, 126, 0.6); }
    .circle-meta { text-align: center; }
}

/* ── Lista de fundadores ─────────────────────────────────────────────────── */
.fundadores { background: #000; scroll-margin-top: 4.5rem; }
.fund-grid { display: grid; gap: 1.5rem; align-items: center; }
.fund-img { width: 100%; aspect-ratio: 16 / 9; object-fit: cover; display: block; border-radius: 12px; }
.fund-card { display: flex; flex-direction: column; gap: 1rem; }
.fund-h { font-size: clamp(2.2rem, 7vw, 3.75rem); }
.fund-p { max-width: 44ch; }
.fund-step { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.2em; color: var(--faint); margin: 0.3rem 0 0; }
.fund-form { display: flex; flex-direction: column; gap: 1.1rem; }
.fund-actions { display: flex; align-items: center; gap: 0.8rem; padding-top: 0.2rem; }
.link-back { background: none; border: none; color: var(--muted); font-family: var(--body); font-size: 0.9rem; text-decoration: underline; cursor: pointer; }
.link-back:hover { color: var(--ink); }
.fund-note { font-size: 0.75rem; line-height: 1.5; color: var(--faint); margin: 0; }
.fund-done { text-align: center; padding: 1.5rem 0; }
.fund-check svg { width: 48px; height: 48px; margin: 0 auto 1rem; }
.fund-done-h { font-size: 1.9rem; }
.fund-done-p { max-width: 24rem; margin: 1rem auto 0; color: var(--muted); line-height: 1.6; }
.fund-done-p strong { color: var(--ink); }

.f-label { display: block; font-size: 0.85rem; font-weight: 500; color: var(--ink); margin-bottom: 0.5rem; }
.f-opt { color: var(--muted); font-weight: 400; }
.f-input { width: 100%; box-sizing: border-box; min-height: 52px; padding: 0 1rem; border-radius: 12px; border: 1px solid rgba(242, 232, 214, 0.22); background: rgba(242, 232, 214, 0.05); color: var(--ink); font-family: var(--body); font-size: 1rem; outline: none; transition: border-color 0.2s ease; }
.f-input::placeholder { color: var(--faint); }
.f-input:focus { border-color: var(--gold); }
.f-err { margin: 0.4rem 0 0; font-size: 0.75rem; color: #e88; }
.f-check { display: flex; align-items: flex-start; gap: 0.7rem; cursor: pointer; font-size: 0.85rem; color: var(--muted); }
.f-check input { margin-top: 0.15rem; width: 1rem; height: 1rem; accent-color: var(--gold); flex-shrink: 0; }
.f-check strong { color: var(--ink); }

.role-toggle, .world-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem; }
.world-sub { margin-top: 1rem; }
.role-opt, .world-opt {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    min-height: 48px;
    padding: 0 0.8rem;
    border-radius: 12px;
    border: 1px solid rgba(242, 232, 214, 0.22);
    background: none;
    color: var(--muted);
    font-family: var(--body);
    font-size: 0.9rem;
    cursor: pointer;
    transition: border-color 0.2s ease, color 0.2s ease, background-color 0.2s ease;
}
.role-opt svg, .world-opt svg { width: 1.1rem; height: 1.1rem; }
.role-opt:hover, .world-opt:hover { border-color: rgba(243, 201, 126, 0.5); }
.role-opt.is-on, .world-opt.is-on { border-color: var(--gold); background: rgba(243, 201, 126, 0.1); color: var(--gold); }

@media (min-width: 768px) {
    .fund-grid { grid-template-columns: 1fr 1fr; gap: 5.5rem; }
}

/* ── Rodapé da landing ───────────────────────────────────────────────────── */
.lc-foot { position: relative; min-height: 420px; overflow: hidden; }
.lc-foot-bg { position: absolute; inset: 0; z-index: 0; }
.lc-foot-img { width: 100%; height: 100%; object-fit: cover; display: block; }
.lc-foot-veil { position: absolute; inset: 0; background: linear-gradient(180deg, #000 0%, rgba(0, 0, 0, 0.55) 40%, rgba(5, 5, 6, 0.92) 100%); }
.lc-foot-inner { position: relative; z-index: 1; padding-top: 5rem; padding-bottom: 2.5rem; display: flex; flex-direction: column; gap: 0.8rem; }
.lc-foot-tag { font-family: var(--serif); font-style: italic; font-size: clamp(2.4rem, 7vw, 3.75rem); color: var(--gold); }
.lc-foot-mark { font-family: 'Cinzel', Georgia, serif; font-size: clamp(1.6rem, 5vw, 2.1rem); letter-spacing: 0.3em; color: var(--ink); }
.lc-foot-nav { margin-top: 2.5rem; display: flex; flex-wrap: wrap; gap: 0.8rem 2rem; }
.lc-foot-nav a { font-size: 0.9rem; color: var(--gold-soft); text-decoration: none; }
.lc-foot-nav a:hover { color: var(--gold); }
.lc-foot-ai { margin: 1.4rem 0 0; max-width: 52ch; font-size: 0.75rem; line-height: 1.6; color: var(--faint); }
.lc-foot-copy { margin: 0.6rem 0 0; padding-top: 1.2rem; border-top: 1px solid rgba(243, 201, 126, 0.18); font-size: 0.72rem; color: var(--faint); }

/* ── Ken Burns + nudge ───────────────────────────────────────────────────── */
@keyframes kb {
    from { transform: scale(1.03); }
    to { transform: scale(1.09) translate(-1%, -1%); }
}
@keyframes nudge {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(6px); }
}
.nudge { animation: nudge 1.8s ease-in-out infinite; }

/* ── Movimento reduzido: tudo estático e legível ─────────────────────────── */
@media (prefers-reduced-motion: reduce) {
    .hscene { transition: none; }
    .hmedia--drift { animation: none; }
    .nudge { animation: none; }
    .destaques-track { transition: none; }
    .btn-gold:hover { transform: none; box-shadow: none; }
    .portal-sticky { position: relative; height: auto; min-height: 70svh; padding: 20svh 0; }
}
</style>
