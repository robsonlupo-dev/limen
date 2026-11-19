<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
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
// Estrutura: (1) HERO de 7 cenas em ciclo automático (sem controles visíveis: a
// troca é por TEMPO nas cenas de imagem e pelo FIM do vídeo — evento `ended` — nas
// de vídeo; copy rotativa; cada cena de vídeo só MONTA o <video> enquanto ativa,
// para sempre recomeçar do zero e nunca cortar antes do fim); (2) O Portal
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
// Transição "saída para preto": a cena que SAI some em .5s; a nova ENTRA em .9s
// com atraso de .5s (espera a anterior sumir) — nunca duas cenas visíveis ao mesmo
// tempo. A cena de IMAGEM avança por TEMPO (heroDurations); a de VÍDEO avança
// quando o vídeo TERMINA (evento `ended`), com um timer só de SEGURANÇA. Cenas de
// vídeo montam o <video> só quando ativas (recomeçam do 1º frame) e fazem preload
// da próxima, para não piscar na troca.
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
let heroTimer = null

// Cena que está SAINDO (fade-out). Mantemos o <video> dela montado enquanto
// some, para segurar o ÚLTIMO frame. Sem isso, o v-if trocava o vídeo que
// acabou pelo <img poster> (o PRIMEIRO frame) no instante do `ended`, e o clipe
// parecia "voltar pro início" por meio segundo antes da próxima cena entrar.
const heroPrev = ref(-1)
let heroPrevTimer = null

// Timer de SEGURANÇA das cenas de vídeo: o avanço real é no evento `ended`; este só
// dispara se o vídeo não terminar (erro de rede/codec). Fica acima do maior vídeo do
// topo (~11s) para nunca cortar a cena antes do fim (ponto nº 2 do fix).
const HERO_VIDEO_FALLBACK_MS = 15000

// Fonte do vídeo da PRÓXIMA cena (null se for imagem), para preload fora de tela.
const heroNextVideoSrc = computed(() => {
    const next = heroScenes[(heroIndex.value + 1) % heroScenes.length]
    return next.type === 'video' ? next.src : null
})

// Saída para preto: a cena ATIVA entra em .9s com atraso de .5s (espera a anterior
// sumir); a que sai some em .5s. Sem movimento → sem transição.
function heroTransition(k) {
    if (!motionOk.value) return 'none'
    return heroIndex.value === k ? 'opacity .9s ease .5s' : 'opacity .5s ease'
}

function heroSchedule(k) {
    clearTimeout(heroTimer)
    if (!motionOk.value) return
    // Imagem: avança por tempo. Vídeo: só o timer de segurança (quem manda é o `ended`).
    const ms = heroScenes[k].type === 'video' ? HERO_VIDEO_FALLBACK_MS : heroDurations[k] * 1000
    heroTimer = setTimeout(() => heroGo((k + 1) % heroScenes.length), ms)
}
function heroGo(k) {
    // Segura a cena que sai montada durante o fade (~.5s de opacity). Um <video>
    // que terminou fica parado no último frame — é esse frame que deve aparecer
    // enquanto a cena some, e não o primeiro frame do poster.
    clearTimeout(heroPrevTimer)
    heroPrev.value = heroIndex.value
    heroPrevTimer = setTimeout(() => { heroPrev.value = -1 }, 650)
    heroIndex.value = k
    heroSchedule(k)
}
function onHeroVideoEnded(k) {
    // Só o vídeo da cena ATIVA avança (ignora `ended` de um vídeo sendo desmontado).
    if (!motionOk.value || heroIndex.value !== k) return
    heroGo((k + 1) % heroScenes.length)
}

// Object-position da mídia da cena (mobile tem recorte próprio no design; aqui
// usamos um valor único por cena que lê bem nos dois). Painel vertical à direita
// (cenas 2 e 5) é tratado no CSS por `.hscene--panel` só no desktop.
function mediaStyle(s) {
    return { objectPosition: s.pos || 'center' }
}

// ── "O Portal se abre com a rolagem" — seção sticky dirigida por scroll ───────
// (feat/landing-limiar-circulos) A cena é a CORTINA (portal-cortina.webp): já entra
// em TELA CHEIA — nunca começa pequena. Ao rolar, a câmera avança para dentro do
// arco (escala máx. 1.7: a fonte tem 2400px, segue nítida), o "Capítulo I" some,
// a névoa dourada toma a tela e o "ATRAVESSE o limiar" aparece sobre o dourado; no
// fim escurece para a seção seguinte. Tudo derivado da POSIÇÃO (simétrico: rolar
// pra cima desfaz), num rAF único.
const portalEl = ref(null)
const portalStickyEl = ref(null)
const portalProgress = ref(0)
let portalRaf = null

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v))

const easeOut3 = (t) => 1 - Math.pow(1 - t, 3)
const portalSpriteScale = computed(() => (1 + easeOut3(clamp(portalProgress.value / 0.75, 0, 1)) * 0.7).toFixed(4))
const portalText1Opacity = computed(() => clamp(1 - portalProgress.value / 0.2, 0, 1))
const portalText1Shift = computed(() => (-40 * (1 - portalText1Opacity.value)).toFixed(1) + 'px')
const portalGold = computed(() => clamp((portalProgress.value - 0.42) / 0.3, 0, 1))
const portalText2Opacity = computed(() => {
    const p = portalProgress.value
    return Math.min(clamp((p - 0.6) / 0.15, 0, 1), clamp((0.94 - p) / 0.06, 0, 1))
})
const portalText2Shift = computed(() => ((1 - clamp((portalProgress.value - 0.6) / 0.15, 0, 1)) * 24).toFixed(1) + 'px')
const portalDark = computed(() => clamp((portalProgress.value - 0.88) / 0.12, 0, 1))

// Poeira dourada (portal e "Só o que é real"): partículas geradas uma vez, posição e
// ritmo aleatórios; puro CSS depois disso. Sem movimento → o CSS some com elas.
function makeDust(n) {
    return Array.from({ length: n }, () => {
        const dur = 12 + Math.random() * 16
        return {
            left: (Math.random() * 100).toFixed(1) + '%',
            size: (1.5 + Math.random() * 2.5).toFixed(1) + 'px',
            dur: dur.toFixed(1) + 's',
            delay: (-Math.random() * dur).toFixed(1) + 's',
            sway: Math.round(Math.random() * 60 - 30) + 'px',
        }
    })
}
const portalDust = makeDust(30)
const realDust = makeDust(24)
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
    { img: '/landing/destaque-1.webp', video: '/landing/destaque-01-convite-envelope.mp4', videoAlt: 'Uma mão retira o convite de um envelope negro, ao lado de uma vela acesa.', alt: 'Envelope lacrado com selo de cera sobre madeira.', lead: 'Exclusivo de verdade.', tail: 'Cada conteúdo é lacrado para quem assina.' },
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
// Pausa o vídeo do card 1 ("Exclusivo de verdade") quando ele sai de vista.
let cardVideoObserver = null

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

// Índice em capítulos (estilo fólio): avança sozinho a cada PD_DUR enquanto a
// seção está visível; pausa no hover/foco e no botão de pausa (WCAG 2.2.2).
// Sem auto-avanço com prefers-reduced-motion (motionOk = false).
const PD_DUR = 6500
const pdEl = ref(null)
const pdIndexEl = ref(null)
const pdInView = ref(false)
const pdHover = ref(false)
const pdUserPaused = ref(false)
const pdTick = ref(0)
const pdHeld = computed(() => pdHover.value || pdUserPaused.value || !pdInView.value || !motionOk.value)
const pdNum = computed(() => String(accIndex.value + 1).padStart(2, '0'))
const pdPauseLabel = computed(() => (pdUserPaused.value ? 'Retomar as telas' : 'Pausar as telas'))
let pdTimer = null
let pdStart = 0
let pdLeft = PD_DUR
let pdObserver = null
function pdArm(reset) {
    clearTimeout(pdTimer)
    pdTimer = null
    if (reset) pdLeft = PD_DUR
    if (pdHeld.value) return
    pdStart = performance.now()
    pdTimer = setTimeout(() => pdGo(accIndex.value + 1), pdLeft)
}
function pdHalt() {
    if (!pdTimer) return
    clearTimeout(pdTimer)
    pdTimer = null
    pdLeft = Math.max(400, pdLeft - (performance.now() - pdStart))
}
function pdGo(i) {
    accIndex.value = (i + accData.length) % accData.length
    pdTick.value++
    pdArm(true)
}
watch(pdHeld, (held) => (held ? pdHalt() : pdArm(false)))
// No celular o índice vira uma faixa horizontal: centraliza o capítulo ativo
// rolando só a faixa (nunca a página).
watch(accIndex, async () => {
    await nextTick()
    const box = pdIndexEl.value
    const on = box?.querySelector('.pd-ch.is-on')?.parentElement
    if (!box || !on || box.scrollWidth <= box.clientWidth) return
    box.scrollTo({ left: on.offsetLeft - (box.clientWidth - on.offsetWidth) / 2, behavior: motionOk.value ? 'smooth' : 'auto' })
})

// ── Só o que é real: 3 garantias em placas 3D ────────────────────────────────
// (feat/landing-limiar-circulos) O ícone de traço virou uma FIGURA animada no nicho
// em arco (escudo, pérola, cadeado — WebP animado self-host). `still` é o pôster
// parado servido a quem pede movimento reduzido.
const realItems = [
    { n: 'I', kicker: 'Identidade', title: 'Criadoras verificadas', desc: 'Biometria e documento em cada perfil. Nenhum fake passa pela porta.', fig: '/landing/real-escudo.webp', still: '/landing/real-escudo-still.webp' },
    { n: 'II', kicker: 'Origem', title: 'Conteúdo exclusivo', desc: 'Direto de quem cria, sem intermediários.', fig: '/landing/real-perola.webp', still: '/landing/real-perola-still.webp' },
    { n: 'III', kicker: 'Pagamento', title: 'PIX e discrição', desc: 'Tokens por PIX, em segundos. Você escolhe como aparece no portal, e quem vê.', fig: '/landing/real-cadeado.webp', still: '/landing/real-cadeado-still.webp' },
]

// ── Diretivas de movimento (só no navegador, só com ponteiro fino e movimento
// permitido; sem elas tudo aparece estático e legível) ────────────────────────
// v-tilt: inclina o elemento seguindo o ponteiro. Publica --rx/--ry (graus) e
// --mx/--my (posição do brilho) como variáveis CSS; o CSS decide o que fazer.
const vTilt = {
    mounted(el, binding) {
        if (typeof window === 'undefined') return
        if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
        const max = binding.value ?? 8
        let raf = 0
        const move = (e) => {
            const r = el.getBoundingClientRect()
            const x = (e.clientX - r.left) / r.width
            const y = (e.clientY - r.top) / r.height
            cancelAnimationFrame(raf)
            raf = requestAnimationFrame(() => {
                el.style.setProperty('--rx', ((0.5 - y) * max).toFixed(2) + 'deg')
                el.style.setProperty('--ry', ((x - 0.5) * max).toFixed(2) + 'deg')
                el.style.setProperty('--mx', (x * 100).toFixed(1) + '%')
                el.style.setProperty('--my', (y * 100).toFixed(1) + '%')
            })
        }
        const leave = () => {
            cancelAnimationFrame(raf)
            el.style.setProperty('--rx', '0deg')
            el.style.setProperty('--ry', '0deg')
        }
        el.addEventListener('pointermove', move)
        el.addEventListener('pointerleave', leave)
        el._tiltOff = () => {
            cancelAnimationFrame(raf)
            el.removeEventListener('pointermove', move)
            el.removeEventListener('pointerleave', leave)
        }
    },
    unmounted(el) { el._tiltOff?.() },
}
// v-reveal: esconde os filhos .rv-item e os revela (em cascata, via --i) quando
// o bloco entra na tela. Só esconde se for conseguir revelar.
const vReveal = {
    mounted(el) {
        if (typeof window === 'undefined' || !('IntersectionObserver' in window)) return
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
        el.classList.add('rv')
        const io = new IntersectionObserver((entries) => {
            if (entries.some((e) => e.isIntersecting)) {
                el.classList.add('is-in')
                io.disconnect()
            }
        }, { threshold: 0.15 })
        io.observe(el)
        el._rvOff = () => io.disconnect()
    },
    unmounted(el) { el._rvOff?.() },
}

// ── Recursos: 3 pilares ──────────────────────────────────────────────────────
const BF = 'Black · Founders'
const pillars = [
    {
        title: 'Discrição', sub: 'Ninguém precisa saber.', img: '/landing/pilar-discricao.webp', pos: '50% 45%',
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
        title: 'Conexão', sub: 'Perto de quem você escolheu.', img: '/landing/pilar-conexao.webp', pos: '50% 24%',
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
        title: 'Exclusividade', sub: 'Um clube, não uma vitrine.', img: '/landing/pilar-exclusividade.webp', pos: '50% 58%',
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

// Pilares: card com foto que ABRE PARA BAIXO. No desktop abre só de passar o
// mouse (CSS :hover); no toque/teclado, o clique alterna `openPillar`.
const openPillar = ref(null)
function pillarToggle(idx) { openPillar.value = openPillar.value === idx ? null : idx }
function pillarClose() { openPillar.value = null }

// ── Círculos (fonte canônica: docs/ECONOMIA.md) ──────────────────────────────
// (feat/landing-limiar-circulos) Acordeão de "portas": os cinco do MESMO tamanho
// fechados (figura apagada + nome + preço); o ativo abre, ganha cor e mostra o
// painel de venda. `perToken` = mensalidade ÷ tokens inclusos (conta exata; todos
// abaixo do Starter R$ 1,00 e acima do piso de margem R$ 0,625 — ECONOMIA §2/§3).
// O desconto é na COMPRA DE PACOTES (ECONOMIA §3), não "em tudo". Pré-lançamento:
// o CTA leva à lista de fundadores (único CTA da landing).
const circles = [
    { name: 'EXPLORADOR', anim: '/landing/circulo-anim-explorador.webp', still: '/landing/circulo-anim-explorador-still.webp', alt: 'Uma chave dourada antiga sob uma fresta de luz.', price: 'R$ 89,90', tokens: '105', discount: '10%', perToken: 'R$ 0,86', seats: null, note: 'A primeira porta.', perk: null },
    { name: 'INSIDER', anim: '/landing/circulo-anim-insider.webp', still: '/landing/circulo-anim-insider-still.webp', alt: 'Uma máscara de filigrana dourada sobre seda negra.', price: 'R$ 189,90', tokens: '230', discount: '10%', perToken: 'R$ 0,83', seats: null, note: 'Para quem já escolheu.', perk: 'Mais presença nas conversas' },
    { name: 'PRESTIGE', anim: '/landing/circulo-anim-prestige.webp', still: '/landing/circulo-anim-prestige-still.webp', alt: 'Uma taça de champanhe de cristal com bolhas subindo.', price: 'R$ 389,90', tokens: '490', discount: '15%', perToken: 'R$ 0,80', seats: null, note: 'Mais perto, mais vezes.', perk: 'Destaque nas suas interações' },
    { name: 'BLACK', anim: '/landing/circulo-anim-black.webp', still: '/landing/circulo-anim-black-still.webp', alt: 'Um frasco de perfume negro ao lado de uma orquídea negra.', price: 'R$ 749,90', tokens: '1.000', discount: '20%', perToken: 'R$ 0,75', seats: '500 vagas', note: 'Discrição total liberada.', perk: 'Privacidade avançada: modo discreto, fantasma e online invisível' },
    { name: 'FOUNDERS', anim: '/landing/circulo-anim-founders.webp', still: '/landing/circulo-anim-founders-still.webp', alt: 'Uma coroa de louros dourada sobre uma almofada de veludo negro.', price: 'R$ 1.490', tokens: '2.100', discount: '25%', perToken: 'R$ 0,71', bestPerToken: true, seats: '100 vagas', note: 'Número seu, para sempre.', perk: 'Número permanente e marcos físicos' },
]

// Sempre há UMA porta aberta (começa na primeira). Desktop com mouse abre no
// hover; toque, clique, Enter/Espaço e foco de teclado também abrem.
const activeCircle = ref(0)
function openCircleDoor(idx) { activeCircle.value = idx }
function onCircleHover(idx) { if (isDesktop.value) activeCircle.value = idx }

const footerLinks = [
    { label: 'Como funciona', href: '#por-dentro' },
    { label: 'Para criadoras', href: '#recursos' },
    { label: 'Círculos', href: '#circulos' },
    { label: 'Lista de fundadores', href: '#fundadores' },
]

// Redes oficiais (@limenoficial). Ícone é SVG (regra do projeto: nunca emoji).
// WhatsApp NÃO entra aqui de propósito: a política de comércio da Meta proíbe
// conteúdo adulto e um link público do número corre risco de ban/denúncia — o
// contato por WhatsApp fica só no fluxo 1:1 do CRM, não exposto na porta pública.
// Instagram/TikTok são contas SFW ("O Portal"), seguras como link de saída.
// `d` é o path do ícone (viewBox 0 0 24 24). Corrija aqui qualquer handle.
const socialLinks = [
    {
        label: 'Telegram',
        href: 'https://t.me/limenoficial',
        d: 'M21.9 4.3 18.5 20.1c-.25 1.13-.92 1.41-1.86.88l-5.16-3.8-2.49 2.4c-.28.27-.5.5-1.02.5l.36-5.18 9.44-8.53c.41-.36-.09-.57-.63-.21L5.9 13.35.86 11.77c-1.1-.34-1.12-1.1.23-1.63l19.68-7.59c.91-.34 1.71.21 1.42 1.59z',
    },
    {
        label: 'Instagram',
        href: 'https://www.instagram.com/limen.oficial/',
        d: 'M12 2.2c3.2 0 3.58 0 4.85.07 1.17.05 1.8.25 2.22.42.56.22.96.48 1.38.9.42.42.68.82.9 1.38.17.42.37 1.05.42 2.22.06 1.27.07 1.65.07 4.85s0 3.58-.07 4.85c-.05 1.17-.25 1.8-.42 2.22a3.7 3.7 0 0 1-.9 1.38c-.42.42-.82.68-1.38.9-.42.17-1.05.37-2.22.42-1.27.06-1.65.07-4.85.07s-3.58 0-4.85-.07c-1.17-.05-1.8-.25-2.22-.42a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.17-.42-.37-1.05-.42-2.22C2.21 15.58 2.2 15.2 2.2 12s0-3.58.07-4.85c.05-1.17.25-1.8.42-2.22.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.17 1.05-.37 2.22-.42C8.42 2.21 8.8 2.2 12 2.2zm0 1.8c-3.14 0-3.5.01-4.74.07-.9.04-1.38.19-1.7.32-.43.16-.74.36-1.06.68-.32.32-.52.63-.68 1.06-.13.32-.28.8-.32 1.7C3.44 9.07 3.43 9.43 3.43 12s.01 2.93.07 4.17c.04.9.19 1.38.32 1.7.16.43.36.74.68 1.06.32.32.63.52 1.06.68.32.13.8.28 1.7.32 1.24.06 1.6.07 4.74.07s3.5-.01 4.74-.07c.9-.04 1.38-.19 1.7-.32.43-.16.74-.36 1.06-.68.32-.32.52-.63.68-1.06.13-.32.28-.8.32-1.7.06-1.24.07-1.6.07-4.17s-.01-2.93-.07-4.17c-.04-.9-.19-1.38-.32-1.7a2.85 2.85 0 0 0-.68-1.06 2.85 2.85 0 0 0-1.06-.68c-.32-.13-.8-.28-1.7-.32C15.5 4.01 15.14 4 12 4zm0 3.07a4.93 4.93 0 1 1 0 9.86 4.93 4.93 0 0 1 0-9.86zm0 1.8a3.13 3.13 0 1 0 0 6.26 3.13 3.13 0 0 0 0-6.26zm5.14-.3a1.15 1.15 0 1 1-2.3 0 1.15 1.15 0 0 1 2.3 0z',
    },
    {
        label: 'TikTok',
        href: 'https://tiktok.com/@limenoficial',
        d: 'M16.5 3c.4 2.3 1.7 3.7 3.9 3.85v2.53c-1.28.12-2.4-.29-3.7-1.07v4.73c0 6-6.56 7.9-9.2 3.58-1.7-2.79-.65-7.68 4.8-7.88v2.67c-.42.07-.86.17-1.27.31-1.23.42-1.92 1.2-1.73 2.57.37 2.63 5.18 3.4 4.78-1.73V3h2.42z',
    },
    {
        label: 'X',
        href: 'https://x.com/limenoficial',
        d: 'M17.53 3h3.1l-6.77 7.74L21.83 21h-6.23l-4.88-6.38L5.14 21H2.03l7.24-8.28L2.17 3h6.39l4.41 5.83L17.53 3zm-1.09 16.13h1.72L7.64 4.78H5.8l10.64 14.35z',
    },
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

    // Pausar o vídeo do card 1 quando ele sai de vista (desliza no carrossel ou a
    // seção sai da tela) e retomar quando reaparece — poupa rede/bateria.
    const cardVideo = trackEl.value?.querySelector('.d-card-video')
    if (cardVideo && 'IntersectionObserver' in window) {
        cardVideoObserver = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) entry.target.play?.().catch(() => {})
                    else entry.target.pause?.()
                }
            },
            { threshold: 0.5 },
        )
        cardVideoObserver.observe(cardVideo)
    }

    // Scroll do Portal.
    window.addEventListener('scroll', onPortalScroll, { passive: true })
    runPortalScroll()

    // Índice do "Por dentro": só avança enquanto a seção está na tela.
    if ('IntersectionObserver' in window && pdEl.value) {
        pdObserver = new IntersectionObserver(([e]) => { pdInView.value = e.isIntersecting }, { threshold: 0.35 })
        pdObserver.observe(pdEl.value)
    }

    document.addEventListener('click', pillarClose)
})

onBeforeUnmount(() => {
    clearTimeout(heroTimer)
    clearTimeout(heroPrevTimer)
    clearInterval(cardTimer)
    cardVideoObserver?.disconnect()
    window.removeEventListener('scroll', onPortalScroll)
    pdObserver?.disconnect()
    clearTimeout(pdTimer)
    document.removeEventListener('click', pillarClose)
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
                    :style="{ opacity: heroIndex === s.k ? 1 : 0, background: s.bg, transition: heroTransition(s.k) }"
                    :aria-hidden="heroIndex === s.k ? 'false' : 'true'"
                >
                    <template v-if="s.type === 'video'">
                        <video
                            v-if="(heroIndex === s.k || heroPrev === s.k) && motionOk"
                            class="hmedia"
                            :src="s.src"
                            :poster="s.poster"
                            autoplay
                            muted
                            playsinline
                            preload="auto"
                            :aria-label="s.alt"
                            :style="mediaStyle(s)"
                            @ended="onHeroVideoEnded(s.k)"
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

                <!-- Preload (fora de tela) do vídeo da próxima cena: evita tela vazia na troca. -->
                <video
                    v-if="motionOk && heroNextVideoSrc"
                    :key="'preload-' + heroNextVideoSrc"
                    class="hero-preload"
                    :src="heroNextVideoSrc"
                    preload="auto"
                    muted
                    playsinline
                    aria-hidden="true"
                    tabindex="-1"
                />

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

            <!-- ══ O Portal se abre com a rolagem (a cortina, em tela cheia) ══ -->
            <section id="portal" ref="portalEl" class="portal" :style="motionOk ? { height: isDesktop ? '3400px' : '2800px' } : {}">
                <div ref="portalStickyEl" class="portal-sticky">
                    <div class="portal-scene" aria-hidden="true" :style="motionOk ? { transform: `scale(${portalSpriteScale})` } : {}">
                        <picture>
                            <source media="(max-width: 767px)" :srcset="'/landing/portal-cortina-mobile.webp'" type="image/webp" />
                            <img class="portal-media" :src="'/landing/portal-cortina.webp'" alt="" width="2400" height="1340" decoding="async" />
                        </picture>
                        <span class="portal-glow" />
                    </div>
                    <div class="lc-dust" aria-hidden="true">
                        <span v-for="(d, di) in portalDust" :key="'pd-' + di" :style="{ left: d.left, width: d.size, height: d.size, animationDuration: d.dur, animationDelay: d.delay, '--sway': d.sway }" />
                    </div>
                    <div class="portal-top-veil" aria-hidden="true" />
                    <div class="portal-gold" aria-hidden="true" :style="{ opacity: motionOk ? portalGold : 0 }" />
                    <div class="portal-rail" aria-hidden="true"><span class="portal-rail-fill" :style="{ height: portalBarHeight }" /></div>

                    <div class="portal-cap" :style="motionOk ? { opacity: portalText1Opacity, transform: `translateY(${portalText1Shift})` } : {}">
                        <div class="eyebrow">Capítulo I · O limiar</div>
                        <div class="portal-h">Toda porta guarda <em>um segredo.</em></div>
                    </div>

                    <div class="portal-cross" :style="{ opacity: motionOk ? portalText2Opacity : 0, transform: `translateY(${portalText2Shift})` }">
                        <div class="portal-cross-a">ATRAVESSE</div>
                        <div class="portal-cross-b">o limiar.</div>
                    </div>

                    <div class="portal-darken" aria-hidden="true" :style="{ opacity: motionOk ? portalDark : 0 }" />
                </div>
            </section>

            <!-- ══ Só o que é real — 3 garantias em placas 3D ══ -->
            <section class="sect marble real">
                <div class="marble-veil" aria-hidden="true" />
                <div class="lc-dust lc-dust--real" aria-hidden="true">
                    <span v-for="(d, di) in realDust" :key="'rd-' + di" :style="{ left: d.left, width: d.size, height: d.size, animationDuration: d.dur, animationDelay: d.delay, '--sway': d.sway }" />
                </div>
                <div class="wrap">
                    <div class="real-head">
                        <div>
                            <div class="eyebrow">Do outro lado</div>
                            <h2 class="h-serif real-title">Só o que é <em>real.</em></h2>
                        </div>
                        <p class="lead real-lead">Um portal de conteúdo adulto verificado, feito no Brasil, para quem valoriza discrição e autenticidade.</p>
                    </div>
                    <ul v-reveal class="real-plates">
                        <li v-for="(r, ri) in realItems" :key="'real-' + ri" class="real-plate rv-item" :style="{ '--i': ri }">
                            <div v-tilt="10" class="real-card">
                                <span class="real-glare" aria-hidden="true" />
                                <span class="real-num" aria-hidden="true">{{ r.n }}</span>
                                <span class="real-ic" aria-hidden="true">
                                    <picture>
                                        <source media="(prefers-reduced-motion: reduce)" :srcset="r.still" type="image/webp" />
                                        <img :src="r.fig" alt="" width="240" height="240" loading="lazy" decoding="async" />
                                    </picture>
                                </span>
                                <div class="real-body">
                                    <div class="real-kicker">{{ r.kicker }}</div>
                                    <h3 class="real-t">{{ r.title }}</h3>
                                    <p class="real-d">{{ r.desc }}</p>
                                </div>
                                <span class="real-rule" aria-hidden="true" />
                            </div>
                        </li>
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
                            <video
                                v-if="c.video && motionOk"
                                class="d-card-img d-card-video"
                                :src="c.video"
                                :poster="c.img"
                                :aria-label="c.videoAlt"
                                autoplay
                                muted
                                loop
                                playsinline
                                preload="metadata"
                            />
                            <img v-else class="d-card-img" :src="c.img" :alt="c.alt" loading="lazy" />
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

            <!-- ══ Por dentro — índice em capítulos (fólio) + telefone 3D ══ -->
            <section id="por-dentro" ref="pdEl" class="sect por-dentro">
                <div class="wrap">
                    <div class="pd-head">
                        <div>
                            <div class="eyebrow">Por dentro do Portal</div>
                            <h2 class="h-serif">Veja de <em>perto.</em></h2>
                        </div>
                        <p class="lead pd-lead">Cinco telas do Portal, do jeito que você vai usar. Escolha um capítulo ou deixe passar sozinho.</p>
                    </div>
                    <div
                        class="pd-body"
                        @mouseenter="pdHover = true"
                        @mouseleave="pdHover = false"
                        @focusin="pdHover = true"
                        @focusout="pdHover = false"
                    >
                        <ol ref="pdIndexEl" class="pd-index">
                            <li v-for="(a, idx) in accData" :key="'ch-' + idx">
                                <button
                                    type="button"
                                    class="pd-ch"
                                    :class="{ 'is-on': accIndex === idx }"
                                    :aria-current="accIndex === idx ? 'true' : null"
                                    aria-controls="pd-panel"
                                    @click="pdGo(idx)"
                                >
                                    <span class="pd-ch-num">{{ String(idx + 1).padStart(2, '0') }}</span>
                                    <span class="pd-ch-title">{{ a[0] }}</span>
                                    <span class="pd-ch-desc"><span>{{ a[1] }}</span></span>
                                    <span class="pd-ch-track" aria-hidden="true">
                                        <span
                                            v-if="accIndex === idx"
                                            :key="'bar-' + pdTick"
                                            class="pd-ch-bar"
                                            :class="{ 'is-held': pdHeld }"
                                            :style="{ animationDuration: PD_DUR + 'ms' }"
                                        />
                                    </span>
                                </button>
                            </li>
                        </ol>

                        <div v-tilt="12" class="pd-stage">
                            <transition name="pd-num" mode="out-in">
                                <span :key="pdNum" class="pd-bignum" aria-hidden="true">{{ pdNum }}</span>
                            </transition>
                            <div class="phone-float">
                                <div class="pd-halo" aria-hidden="true" />
                                <div class="phone">
                                    <div id="pd-panel" class="phone-screen">
                                        <div class="phone-status" aria-hidden="true">
                                            <span>21:00</span>
                                            <span class="phone-status-ic">
                                                <svg width="16" height="10" viewBox="0 0 16 10" fill="currentColor"><path d="M1 9h2V7H1zM5 9h2V5H5zM9 9h2V3H9zM13 9h2V1h-2z" /></svg>
                                                <svg width="22" height="10" viewBox="0 0 22 10"><rect x="0.5" y="0.5" width="18" height="9" rx="2.5" fill="none" stroke="currentColor" /><rect x="2" y="2" width="13" height="6" rx="1.5" fill="currentColor" /><rect x="19.5" y="3.5" width="1.5" height="3" rx="0.75" fill="currentColor" /></svg>
                                            </span>
                                        </div>
                                        <div class="phone-notch" aria-hidden="true" />
                                        <!-- Tela 0 · Verificação -->
                                        <transition name="ps-swap">
                                            <div v-show="accIndex === 0" class="ps ps-center">
                                                <img class="ps-avatar" :src="'/landing/destaque-2.webp'" alt="" />
                                                <div class="ps-h">Verificação do Portal</div>
                                                <div class="ps-sub">Confidencial. Não aparece no seu perfil nem para membros.</div>
                                                <div class="ps-list">
                                                    <div class="ps-row">✓ Mostre que você é real</div>
                                                    <div class="ps-row">✓ Apareça em destaque no catálogo</div>
                                                    <div class="ps-row">✓ Proteja seu nome e seu conteúdo</div>
                                                </div>
                                                <div class="ps-foot">Feita uma única vez, com total sigilo.</div>
                                            </div>
                                        </transition>
                                        <!-- Tela 1 · Carteira -->
                                        <transition name="ps-swap">
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
                                        </transition>
                                        <!-- Tela 2 · Gorjeta -->
                                        <transition name="ps-swap">
                                            <div v-show="accIndex === 2" class="ps ps-center ps-mid">
                                                <img class="ps-avatar ps-avatar--sm" :src="'/landing/destaque-5.webp'" alt="" />
                                                <div class="ps-name">Criadora verificada <span class="lc-gold">✓</span></div>
                                                <div class="ps-sub">Sua gorjeta aparece em destaque para ela.</div>
                                                <div class="ps-tips">
                                                    <div class="ps-tip">10</div>
                                                    <div class="ps-tip is-on">25</div>
                                                    <div class="ps-tip">50</div>
                                                </div>
                                                <div class="ps-msg">Deixe uma mensagem…</div>
                                                <div class="ps-foot">Gorjeta de 25 tokens, com a sua mensagem.</div>
                                            </div>
                                        </transition>
                                        <!-- Tela 3 · Privacidade -->
                                        <transition name="ps-swap">
                                            <div v-show="accIndex === 3" class="ps">
                                                <div class="ps-h ps-h--sm">Privacidade</div>
                                                <div class="ps-toggle"><span>Modo fantasma</span><span class="sw is-on" aria-hidden="true"><i /></span></div>
                                                <div class="ps-toggle"><span>Apelido de fã</span><span class="lc-gold">@noturno</span></div>
                                                <div class="ps-toggle"><span>Modo discreto</span><span class="sw is-on" aria-hidden="true"><i /></span></div>
                                                <div class="ps-toggle"><span>Confirmação de leitura</span><span class="sw" aria-hidden="true"><i /></span></div>
                                                <div class="ps-toggle"><span>Mostrar que estou online</span><span class="sw" aria-hidden="true"><i /></span></div>
                                                <div class="ps-foot ps-foot--left">Criadoras veem seu apelido, nunca seu nome.</div>
                                            </div>
                                        </transition>
                                        <!-- Tela 4 · PIX -->
                                        <transition name="ps-swap">
                                            <div v-show="accIndex === 4" class="ps ps-center ps-mid">
                                                <div class="ps-label">Pagar com PIX</div>
                                                <div class="ps-price">R$ 39,90</div>
                                                <div class="ps-qr" aria-hidden="true"><div class="ps-qr-grid" /></div>
                                                <div class="ps-sub">Expira em <span class="lc-gold">4:32</span></div>
                                                <div class="ps-foot">Pagamento instantâneo, o saldo cai na hora.</div>
                                                <div class="ps-foot">QR ilustrativo · Os tokens caem assim que o PIX é confirmado.</div>
                                            </div>
                                        </transition>
                                        <span class="phone-glare" aria-hidden="true" />
                                        <span class="phone-home" aria-hidden="true" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p class="pd-caption">{{ accData[accIndex][1] }}</p>

                        <div class="pd-ctrl">
                            <button type="button" class="round-btn" aria-label="Tela anterior" @click="pdGo(accIndex - 1)">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 3L5 8l5 5" /></svg>
                            </button>
                            <span class="pd-count"><b>{{ pdNum }}</b> / {{ String(accData.length).padStart(2, '0') }}</span>
                            <button v-if="motionOk" type="button" class="round-btn" :aria-label="pdPauseLabel" @click="pdUserPaused = !pdUserPaused">
                                <svg v-if="!pdUserPaused" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M5 3v10M11 3v10" /></svg>
                                <svg v-else width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M5 3l8 5-8 5z" /></svg>
                            </button>
                            <button type="button" class="round-btn" aria-label="Próxima tela" @click="pdGo(accIndex + 1)">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3l5 5-5 5" /></svg>
                            </button>
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
                        <div
                            v-for="(pl, pi) in pillars"
                            :key="'pl-' + pi"
                            class="pillar"
                            :class="{ 'is-open': openPillar === pi }"
                        >
                            <button
                                type="button"
                                class="pillar-head"
                                :aria-expanded="openPillar === pi"
                                :aria-controls="'pillar-list-' + pi"
                                @click.stop="pillarToggle(pi)"
                                @keydown.esc="pillarClose"
                            >
                                <img class="pillar-img" :src="pl.img" :style="{ objectPosition: pl.pos }" alt="" loading="lazy" decoding="async" />
                                <span class="pillar-shade" aria-hidden="true" />
                                <span class="pillar-cap">
                                    <span class="pillar-ic">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="pl.icon" /></svg>
                                    </span>
                                    <span class="pillar-txt">
                                        <span class="pillar-title">{{ pl.title }}</span>
                                        <span class="pillar-sub">{{ pl.sub }}</span>
                                    </span>
                                    <span class="pillar-chev" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6" /></svg>
                                    </span>
                                </span>
                            </button>
                            <div :id="'pillar-list-' + pi" class="pillar-list">
                                <div class="pillar-list-in">
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
                    </div>
                </div>
            </section>

            <!-- ══ Círculos — acordeão de portas ══ -->
            <section id="circulos" class="sect dark circulos">
                <div class="wrap circulos-head">
                    <div>
                        <div class="eyebrow">Os Círculos</div>
                        <h2 class="h-serif">Cada círculo, <em>uma porta a mais.</em></h2>
                    </div>
                    <div class="circulos-aside">
                        <p class="lead">Assinatura mensal com tokens incluídos e desconto nos pacotes de tokens. Os dois círculos mais altos têm vagas contadas.</p>
                        <div class="circulos-count"><span>05</span><small>Círculos</small></div>
                    </div>
                </div>
                <div v-reveal class="cdoors">
                    <div
                        v-for="(ci, idx) in circles"
                        :key="'ci-' + idx"
                        class="cdoor rv-item"
                        :class="{ 'is-open': activeCircle === idx }"
                        :style="{ '--i': idx }"
                        tabindex="0"
                        role="button"
                        :aria-expanded="activeCircle === idx"
                        :aria-label="'Círculo ' + ci.name + ', ' + ci.price + ' por mês'"
                        @mouseenter="onCircleHover(idx)"
                        @focus="openCircleDoor(idx)"
                        @click="openCircleDoor(idx)"
                        @keydown.enter.prevent="openCircleDoor(idx)"
                        @keydown.space.prevent="openCircleDoor(idx)"
                    >
                        <picture class="cdoor-fig">
                            <source media="(prefers-reduced-motion: reduce)" :srcset="ci.still" type="image/webp" />
                            <img :src="ci.anim" :alt="ci.alt" width="384" height="576" loading="lazy" decoding="async" />
                        </picture>
                        <span class="cdoor-shade" aria-hidden="true" />

                        <!-- Fechada: número, nome e preço -->
                        <div class="cdoor-closed" :aria-hidden="activeCircle === idx">
                            <span class="cdoor-num">0{{ idx + 1 }}</span>
                            <span class="cdoor-name" :class="{ 'lc-gold': ci.seats }">{{ ci.name }}</span>
                            <span class="cdoor-mini">{{ ci.price }}</span>
                        </div>

                        <!-- Aberta: o painel de venda -->
                        <div class="cdoor-open" :aria-hidden="activeCircle !== idx">
                            <span class="cdoor-kicker">Círculo 0{{ idx + 1 }} · {{ ci.note }}</span>
                            <h3 class="cdoor-title">{{ ci.name }}</h3>
                            <div class="cdoor-reveal"><div>
                                <div class="cdoor-price">{{ ci.price }}<span>/mês</span></div>
                                <ul class="cdoor-perks">
                                    <li><span><b>{{ ci.tokens }} tokens</b> todo mês</span></li>
                                    <li><span><b>{{ ci.discount }}</b> de desconto nos pacotes de tokens</span></li>
                                    <li><span>{{ ci.perToken }} por token incluso<template v-if="ci.bestPerToken">, o menor de todos</template></span></li>
                                    <li v-if="ci.perk"><span>{{ ci.perk }}</span></li>
                                </ul>
                                <div v-if="ci.seats" class="circle-seats">Apenas {{ ci.seats }}</div>
                                <button type="button" class="cdoor-cta" :tabindex="activeCircle === idx ? 0 : -1" @click.stop="scrollToForm('member')">
                                    <span>Garantir minha vaga</span><span class="cdoor-cta-arrow" aria-hidden="true">→</span>
                                </button>
                            </div></div>
                        </div>
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
                                <p class="fund-done-p" style="margin-top:0.75rem; font-size:0.9rem;">
                                    Não recebeu? <a :href="route('waitlist.resend.show')" class="lc-gold" style="text-decoration:underline;">Reenviar o e-mail de confirmação</a>.
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
                                        <span class="f-label">O que você procura no Limen?</span>
                                        <div class="role-toggle" role="group" aria-label="O que você procura">
                                            <button type="button" class="role-opt" :class="{ 'is-on': form.role === 'member' }" @click="selectRole('member')">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>Conhecer e conversar
                                            </button>
                                            <button type="button" class="role-opt" :class="{ 'is-on': form.role === 'performer' }" @click="selectRole('performer')">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z" /></svg>Ser descoberta e ganhar
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
                                            <span>Confirmo que tenho <strong>18 anos ou mais</strong> e concordo em receber o convite de lançamento por e-mail. Li a <a :href="route('legal.privacy')" target="_blank" rel="noopener">Política de Privacidade</a>.</span>
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
                    <nav class="lc-foot-nav" aria-label="Documentos legais">
                        <a :href="route('legal.terms')">Termos de uso</a>
                        <a :href="route('legal.privacy')">Privacidade</a>
                        <a :href="route('legal.content-policy')">Conteúdo e moderação</a>
                        <a :href="route('legal.age-verification')">Conformidade 18+</a>
                    </nav>
                    <nav class="lc-foot-social" aria-label="Redes sociais">
                        <a
                            v-for="s in socialLinks"
                            :key="s.label"
                            :href="s.href"
                            target="_blank"
                            rel="noopener"
                            :aria-label="s.label"
                            :title="s.label"
                        >
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true">
                                <path :d="s.d" />
                            </svg>
                        </a>
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
    /* A transição de opacidade é inline por cena (heroTransition) — saída para preto. */
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

/* Preloader da próxima cena de vídeo: presente no DOM, mas invisível e inerte. */
.hero-preload {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    pointer-events: none;
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

/* ── O Portal se abre com a rolagem (a cortina em tela cheia) ────────────── */
.portal { position: relative; background: var(--bg); }
.portal-sticky {
    position: sticky;
    top: 0;
    height: 100svh;
    overflow: hidden;
    background: var(--bg);
    display: flex;
}
.lc--static .portal-sticky { position: relative; height: auto; min-height: 88svh; padding: 20svh 0; }
/* A cena ocupa a tela INTEIRA desde o primeiro frame; o zoom da rolagem nasce no
   centro do arco (50% 42%). */
.portal-scene {
    position: absolute;
    inset: 0;
    transform-origin: 50% 42%;
    will-change: transform;
}
.portal-scene picture { display: block; width: 100%; height: 100%; }
.portal-media {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: 50% 40%;
    animation: portal-breathe 18s ease-in-out infinite alternate;
}
@keyframes portal-breathe { from { transform: scale(1); } to { transform: scale(1.035); } }
/* Brilho que pulsa dentro do arco. */
.portal-glow {
    position: absolute;
    left: 50%;
    top: 42%;
    width: 70vw;
    height: 60svh;
    transform: translate(-50%, -50%);
    background: radial-gradient(closest-side, rgba(255, 214, 140, 0.32), rgba(232, 170, 80, 0.12) 55%, transparent 100%);
    mix-blend-mode: screen;
    animation: portal-pulse 6s ease-in-out infinite;
    pointer-events: none;
}
@keyframes portal-pulse { 0%, 100% { opacity: 0.55; } 50% { opacity: 1; } }
.portal-top-veil {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(120% 90% at 50% 40%, transparent 55%, rgba(5, 5, 6, 0.75) 100%),
        linear-gradient(180deg, rgba(5, 5, 6, 0.55) 0%, rgba(5, 5, 6, 0) 18%, rgba(5, 5, 6, 0) 55%, rgba(5, 5, 6, 0.94) 100%);
}
/* A névoa dourada que toma a tela na travessia. */
.portal-gold {
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at 50% 45%, rgba(255, 220, 160, 0.92), rgba(201, 150, 70, 0.62) 45%, #050404 100%);
    pointer-events: none;
}
.portal-rail {
    position: absolute;
    left: 1.3rem;
    top: 18%;
    width: 2px;
    height: 160px;
    background: rgba(242, 232, 214, 0.14);
    z-index: 2;
}
.portal-rail-fill { display: block; width: 2px; background: var(--gold); }
.portal-cap,
.portal-cross {
    position: absolute;
    left: 0;
    bottom: 0;
    z-index: 3;
    width: 100%;
    box-sizing: border-box;
    padding: 0 1.3rem 14svh;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.6rem;
    text-align: center;
}
.portal-h {
    font-family: var(--serif);
    font-size: clamp(2.3rem, 7vw, 4.6rem);
    line-height: 1.05;
    color: var(--ink);
    text-shadow: 0 4px 30px rgba(0, 0, 0, 0.6);
}
.portal-h em { font-style: italic; color: var(--gold); }
/* O segundo título entra SOBRE o dourado: tinta escura. */
.portal-cross { gap: 0.3rem; padding-bottom: 22svh; }
.portal-cross-a { font-family: var(--disp); font-size: clamp(2rem, 6vw, 3.5rem); letter-spacing: 0.18em; color: var(--on-gold); }
.portal-cross-b { font-family: var(--serif); font-style: italic; font-size: clamp(2rem, 6vw, 3.6rem); color: #3a2710; }
.portal-darken { position: absolute; inset: 0; z-index: 4; background: var(--bg); pointer-events: none; }
@media (min-width: 768px) {
    .portal-glow { width: 34vw; }
    .portal-rail { left: 5.5rem; top: 33%; height: 233px; }
    .portal-cap, .portal-cross { padding-left: 5.5rem; padding-right: 5.5rem; }
}

/* ── Poeira dourada (portal e "Só o que é real") ─────────────────────────── */
.lc-dust { position: absolute; inset: 0; z-index: 1; overflow: hidden; pointer-events: none; }
.lc-dust span {
    position: absolute;
    bottom: -10px;
    border-radius: 50%;
    background: radial-gradient(circle, #fff3cf, rgba(201, 162, 75, 0.2) 60%, transparent 70%);
    opacity: 0;
    animation: lc-rise linear infinite;
}
@keyframes lc-rise {
    0% { transform: translate(0, 0); opacity: 0; }
    10% { opacity: 0.9; }
    90% { opacity: 0.5; }
    100% { transform: translate(var(--sway, 20px), -110svh); opacity: 0; }
}
.lc--static .lc-dust { display: none; }

/* ── Seções genéricas ────────────────────────────────────────────────────── */
.sect { position: relative; padding: 5.5rem 0; }
@media (min-width: 768px) { .sect { padding: 9rem 0; } }
.dark { background: var(--bg); border-top: 1px solid rgba(243, 201, 126, 0.1); }
.marble { position: relative; background-image: url('/landing/fundo.webp'); background-size: cover; background-position: center; border-top: 1px solid rgba(243, 201, 126, 0.1); }
.marble-veil { position: absolute; inset: 0; background: linear-gradient(180deg, var(--bg) 0%, rgba(5, 5, 6, 0.86) 18%, rgba(5, 5, 6, 0.8) 100%); }
.marble-veil--strong { background: rgba(5, 5, 6, 0.9); }
.marble > .wrap { position: relative; z-index: 1; }

/* ── Só o que é real — 3 garantias em placas 3D ───────────────────────────── */
.real-head { display: flex; flex-direction: column; gap: 1.1rem; margin-bottom: 2.6rem; }
.real-title { font-size: clamp(3rem, 11vw, 5.5rem); }
.real-lead { max-width: 400px; }
.real-plates { list-style: none; margin: 0; padding: 0; display: grid; gap: 1rem; }
.real-plate { perspective: 1000px; }
.real-card {
    position: relative;
    isolation: isolate;
    height: 100%;
    min-height: 260px;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    gap: 1.3rem;
    padding: 7.6rem 1.5rem 1.7rem;
    border-radius: 22px;
    background: linear-gradient(165deg, rgba(36, 28, 19, 0.94) 0%, rgba(14, 12, 10, 0.96) 62%);
    box-shadow: inset 0 0 0 1px rgba(243, 201, 126, 0.22), 0 24px 60px -24px rgba(0, 0, 0, 0.8);
    transform: rotateX(var(--rx, 0deg)) rotateY(var(--ry, 0deg));
    transform-style: preserve-3d;
    transition: transform 0.55s cubic-bezier(0.2, 0.7, 0.2, 1), box-shadow 0.35s ease;
}
.real-glare { position: absolute; inset: 0; border-radius: inherit; background: radial-gradient(420px circle at var(--mx, 50%) var(--my, 0%), rgba(243, 201, 126, 0.17), transparent 55%); opacity: 0; transition: opacity 0.4s ease; pointer-events: none; }
.real-num { position: absolute; top: 0.5rem; right: 1.2rem; font-family: 'Cinzel', Georgia, serif; font-size: 5.5rem; line-height: 1; color: transparent; -webkit-text-stroke: 1px rgba(243, 201, 126, 0.34); transform: translateZ(46px); transition: -webkit-text-stroke-color 0.4s ease; pointer-events: none; user-select: none; }
.real-ic { position: absolute; top: 1.4rem; left: 1.5rem; width: 66px; height: 76px; box-sizing: border-box; border-radius: 33px 33px 10px 10px; border: 1px solid rgba(243, 201, 126, 0.45); background: #0d0b09; overflow: hidden; transform: translateZ(32px); transition: border-color 0.5s ease, box-shadow 0.5s ease; }
.real-ic picture, .real-ic img { display: block; width: 100%; height: 100%; object-fit: cover; }
.real-ic img { transition: transform 0.8s ease; }
/* O "raio": um ponto de luz dourada que corre a borda do card. */
@property --lc-ray { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
.real-card::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    padding: 1.5px;
    background: conic-gradient(from var(--lc-ray), transparent 0deg, transparent 232deg, rgba(243, 201, 126, 0.25) 252deg, var(--gold) 285deg, #fff3cf 300deg, var(--gold) 315deg, rgba(243, 201, 126, 0.25) 335deg, transparent 352deg);
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor;
    mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    mask-composite: exclude;
    opacity: 0;
    transition: opacity 0.35s ease;
    pointer-events: none;
    z-index: 2;
}
@keyframes lc-ray { to { --lc-ray: 360deg; } }
.real-rule { position: relative; overflow: hidden; }
.real-rule::after { content: ''; position: absolute; top: 0; left: -40%; width: 40%; height: 100%; background: linear-gradient(90deg, transparent, #fff3cf, transparent); opacity: 0; }
@keyframes lc-slide { 0% { left: -40%; opacity: 1; } 100% { left: 110%; opacity: 1; } }
/* Toque (sem hover): o raio dá UMA volta quando o card entra na tela. */
@media (hover: none) {
    .rv.is-in .real-card::before { animation: lc-ray 2.6s linear calc(var(--i, 0) * 300ms + 900ms) 1 both; opacity: 1; }
}
.lc-dust--real { z-index: 0; }
.real-body { position: relative; transform: translateZ(22px); }
.real-kicker { margin-bottom: 0.65rem; font-size: 0.66rem; font-weight: 600; letter-spacing: 0.24em; text-transform: uppercase; color: var(--gold); }
.real-t { margin: 0 0 0.55rem; font-family: var(--serif); font-size: 2rem; font-weight: 400; line-height: 1.05; color: var(--ink); }
.real-d { margin: 0; font-size: 0.96rem; line-height: 1.6; color: var(--muted); }
.real-rule { display: block; height: 1px; background: linear-gradient(90deg, var(--gold), rgba(243, 201, 126, 0)); transform-origin: left; }
@media (hover: hover) and (pointer: fine) {
    .real-card:hover { box-shadow: inset 0 0 0 1px rgba(243, 201, 126, 0.7), 0 34px 70px -24px rgba(0, 0, 0, 0.9), 0 0 70px -24px rgba(243, 201, 126, 0.4); }
    .real-card:hover .real-glare { opacity: 1; }
    .real-card:hover .real-num { -webkit-text-stroke-color: rgba(243, 201, 126, 0.8); }
    .real-card:hover::before { opacity: 1; animation: lc-ray 1.5s linear infinite; }
    .real-card:hover .real-ic { border-color: rgba(243, 201, 126, 0.85); box-shadow: 0 0 30px -4px rgba(243, 201, 126, 0.5); }
    .real-card:hover .real-ic img { transform: scale(1.1); }
    .real-card:hover .real-rule::after { animation: lc-slide 1.3s ease-in-out infinite; }
}
/* Revelação na rolagem: o v-reveal só põe .rv quando vai conseguir revelar. */
.rv .rv-item { opacity: 0; transform: translateY(36px); }
.rv.is-in .rv-item { opacity: 1; transform: none; transition: opacity 0.9s ease, transform 1.1s cubic-bezier(0.2, 0.7, 0.2, 1); transition-delay: calc(var(--i, 0) * 140ms); }
.rv .real-rule { transform: scaleX(0); }
.rv.is-in .real-rule { transform: scaleX(1); transition: transform 1.4s cubic-bezier(0.2, 0.7, 0.2, 1); transition-delay: calc(var(--i, 0) * 140ms + 450ms); }
@media (min-width: 768px) {
    .real-head { flex-direction: row; align-items: flex-end; justify-content: space-between; gap: 3.4rem; margin-bottom: 3.6rem; }
    .real-plates { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.4rem; }
    .real-card { min-height: 390px; padding: 2rem 1.9rem 2.1rem; }
    .real-d { min-height: 3.2em; }
    .real-ic { top: 1.8rem; left: 1.9rem; width: 84px; height: 96px; border-radius: 42px 42px 14px 14px; }
    .real-num { top: 0.7rem; right: 1.5rem; font-size: 7.5rem; }
    .real-t { font-size: 2.15rem; }
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

/* ── Por dentro — índice em capítulos (fólio) + telefone 3D ───────────────── */
.por-dentro { position: relative; background: radial-gradient(120% 70% at 85% 45%, #17110c 0%, #0b090d 55%); border-top: 1px solid rgba(243, 201, 126, 0.1); overflow: hidden; }
.por-dentro .h-serif { font-size: clamp(2.8rem, 8vw, 5rem); margin-top: 0.4rem; }
.pd-head { display: flex; flex-direction: column; gap: 1.1rem; margin-bottom: 2rem; }
.pd-lead { max-width: 380px; }
.pd-body { display: grid; grid-template-columns: minmax(0, 1fr); grid-template-areas: "index" "stage" "caption" "ctrl"; gap: 1.2rem; }

/* Índice — no celular: faixa horizontal de capítulos */
.pd-index { grid-area: index; position: relative; list-style: none; margin: 0 -1.3rem; padding: 2px 1.3rem; display: flex; gap: 8px; overflow-x: auto; scroll-snap-type: x proximity; scrollbar-width: none; }
.pd-index::-webkit-scrollbar { display: none; }
.pd-index li { flex: 0 0 auto; scroll-snap-align: center; }
.pd-ch { position: relative; display: flex; align-items: baseline; gap: 0.55rem; padding: 0.7rem 1.05rem 0.8rem; border: none; border-radius: 999px; background: rgba(242, 232, 214, 0.05); box-shadow: inset 0 0 0 1px rgba(243, 201, 126, 0.18); color: inherit; font: inherit; text-align: left; cursor: pointer; overflow: hidden; -webkit-tap-highlight-color: transparent; transition: background-color 0.3s ease, box-shadow 0.3s ease; }
.pd-ch:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
.pd-ch-num { font-family: 'Cinzel', Georgia, serif; font-size: 0.72rem; letter-spacing: 0.14em; color: var(--faint); transition: color 0.3s ease; }
.pd-ch-title { font-size: 0.92rem; font-weight: 600; color: rgba(242, 232, 214, 0.6); white-space: nowrap; transition: color 0.35s ease, transform 0.5s cubic-bezier(0.2, 0.7, 0.2, 1); }
.pd-ch-desc { display: none; }
.pd-ch.is-on { background: rgba(243, 201, 126, 0.1); box-shadow: inset 0 0 0 1px var(--gold); }
.pd-ch.is-on .pd-ch-num { color: var(--gold); }
.pd-ch.is-on .pd-ch-title { color: var(--ink); }
.pd-ch-track { position: absolute; left: 16px; right: 16px; bottom: 5px; height: 1px; pointer-events: none; }
.pd-ch-bar { display: block; height: 100%; background: var(--gold); box-shadow: 0 0 10px rgba(243, 201, 126, 0.6); transform: scaleX(0); transform-origin: left; animation-name: pd-fill; animation-timing-function: linear; animation-fill-mode: forwards; }
.pd-ch-bar.is-held { animation-play-state: paused; }
@keyframes pd-fill { to { transform: scaleX(1); } }

/* Palco do telefone */
.pd-stage { grid-area: stage; position: relative; display: grid; place-items: center; min-height: 620px; perspective: 1400px; }
.pd-halo { position: absolute; left: 50%; top: 50%; width: 520px; height: 520px; margin: -260px 0 0 -260px; border-radius: 50%; background: radial-gradient(closest-side, rgba(243, 201, 126, 0.24), rgba(243, 201, 126, 0.07) 55%, transparent 74%); pointer-events: none; }
.pd-bignum { display: none; position: absolute; left: -0.04em; top: 50%; margin-top: -0.95em; font-family: 'Cinzel', Georgia, serif; font-size: 9.5rem; line-height: 1.2; letter-spacing: -0.02em; color: transparent; -webkit-text-stroke: 1px rgba(243, 201, 126, 0.2); pointer-events: none; user-select: none; }
.pd-num-enter-active, .pd-num-leave-active { transition: opacity 0.45s ease, transform 0.6s cubic-bezier(0.2, 0.7, 0.2, 1); }
.pd-num-enter-from { opacity: 0; transform: translateY(40px); }
.pd-num-leave-to { opacity: 0; transform: translateY(-40px); }
.phone-float { position: relative; z-index: 1; display: grid; place-items: center; animation: pd-float 7s ease-in-out infinite; }
@keyframes pd-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-12px); } }

.phone {
    position: relative;
    z-index: 1;
    width: 280px;
    height: 580px;
    flex-shrink: 0;
    box-sizing: border-box;
    padding: 10px;
    border-radius: 46px;
    background: linear-gradient(150deg, #2c2631 0%, #16131b 45%, #0c0a0f 100%);
    box-shadow:
        0 0 0 1px rgba(243, 201, 126, 0.45),
        inset 0 0 0 1.5px rgba(255, 255, 255, 0.06),
        0 50px 90px -30px rgba(0, 0, 0, 0.9),
        0 0 110px -20px rgba(243, 201, 126, 0.22);
    transform: rotateX(var(--rx, 0deg)) rotateY(var(--ry, 0deg));
    transition: transform 0.6s cubic-bezier(0.2, 0.7, 0.2, 1);
}
.phone::before, .phone::after { content: ''; position: absolute; width: 3px; border-radius: 2px; background: linear-gradient(180deg, #3b3342, #1c1820); }
.phone::before { left: -3px; top: 118px; height: 52px; box-shadow: 0 70px 0 #2b2531; }
.phone::after { right: -3px; top: 160px; height: 82px; }
.phone-screen { pointer-events: none; user-select: none; position: relative; width: 100%; height: 100%; border-radius: 37px; background: radial-gradient(130% 70% at 50% 0%, #1a1520 0%, #0c0a10 60%); overflow: hidden; }
.phone-status { position: absolute; top: 15px; left: 26px; right: 22px; z-index: 3; display: flex; align-items: center; justify-content: space-between; font-size: 0.72rem; font-weight: 600; color: var(--ink); }
.phone-status-ic { display: inline-flex; align-items: center; gap: 5px; }
.phone-notch { position: absolute; left: 50%; top: 10px; width: 84px; height: 24px; margin-left: -42px; border-radius: 12px; background: #000; z-index: 4; }
.phone-glare { position: absolute; inset: 0; z-index: 5; border-radius: inherit; pointer-events: none; background: linear-gradient(115deg, transparent 32%, rgba(255, 255, 255, 0.07) 46%, rgba(255, 255, 255, 0.015) 56%, transparent 70%); background-size: 260% 100%; background-position: var(--mx, 50%) 0; transition: background-position 0.6s cubic-bezier(0.2, 0.7, 0.2, 1); }
.phone-home { position: absolute; left: 50%; bottom: 9px; z-index: 5; width: 108px; height: 4px; margin-left: -54px; border-radius: 2px; background: rgba(242, 232, 214, 0.35); }
.ps-swap-enter-active { transition: opacity 0.55s ease 0.12s, transform 0.75s cubic-bezier(0.2, 0.7, 0.2, 1) 0.12s, filter 0.55s ease 0.12s; }
.ps-swap-leave-active { transition: opacity 0.35s ease, transform 0.45s ease, filter 0.35s ease; }
.ps-swap-enter-from { opacity: 0; transform: translateY(18px) scale(0.98); filter: blur(6px); }
.ps-swap-leave-to { opacity: 0; transform: translateY(-12px) scale(0.99); filter: blur(4px); }
.ps { position: absolute; inset: 0; padding: 62px 21px 30px; display: flex; flex-direction: column; gap: 13px; box-sizing: border-box; overflow-y: auto; }
.ps-center { align-items: center; text-align: center; }
.ps-mid { justify-content: center; padding-bottom: 46px; }
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

.pd-caption { grid-area: caption; justify-self: center; max-width: 34rem; min-height: 4.8em; margin: 0; font-size: 0.95rem; line-height: 1.6; text-align: center; color: var(--muted); }
.pd-ctrl { grid-area: ctrl; display: flex; align-items: center; justify-content: center; gap: 10px; }
.pd-count { min-width: 74px; font-family: 'Cinzel', Georgia, serif; font-size: 0.8rem; letter-spacing: 0.16em; text-align: center; color: var(--muted); }
.pd-count b { font-weight: 400; color: var(--gold); }

@media (min-width: 900px) {
    .pd-head { flex-direction: row; align-items: flex-end; justify-content: space-between; gap: 3.4rem; margin-bottom: 3rem; }
    .pd-body { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); grid-template-areas: "index stage" "index ctrl"; column-gap: 4.5rem; row-gap: 0.4rem; align-items: center; }
    .pd-caption { display: none; }
    /* Índice — no desktop: lista vertical, estilo sumário de fólio */
    .pd-index { display: block; margin: 0; padding: 0; overflow: visible; }
    .pd-index li { border-top: 1px solid rgba(243, 201, 126, 0.16); }
    .pd-index li:last-child { border-bottom: 1px solid rgba(243, 201, 126, 0.16); }
    .pd-ch { width: 100%; display: grid; grid-template-columns: 3.2rem minmax(0, 1fr); grid-template-areas: "num title" ". desc"; align-items: baseline; padding: 1.35rem 0; border-radius: 0; background: none; box-shadow: none; overflow: visible; }
    .pd-ch.is-on { background: none; box-shadow: none; }
    .pd-ch-num { grid-area: num; font-size: 0.8rem; }
    .pd-ch-title { grid-area: title; font-family: var(--serif); font-size: clamp(1.8rem, 2.6vw, 2.4rem); font-weight: 400; line-height: 1.1; color: rgba(242, 232, 214, 0.38); white-space: normal; }
    .pd-ch:hover .pd-ch-title { color: rgba(242, 232, 214, 0.72); }
    .pd-ch.is-on .pd-ch-title { color: var(--ink); transform: translateX(6px); }
    .pd-ch-desc { grid-area: desc; display: grid; grid-template-rows: 0fr; transition: grid-template-rows 0.55s cubic-bezier(0.2, 0.7, 0.2, 1); }
    .pd-ch-desc > span { min-height: 0; overflow: hidden; max-width: 30rem; padding-left: 6px; font-size: 1rem; line-height: 1.6; color: var(--muted); }
    .pd-ch-desc > span::before { content: ''; display: block; height: 0.7rem; }
    .pd-ch.is-on .pd-ch-desc { grid-template-rows: 1fr; }
    .pd-ch-track { left: 0; right: 0; bottom: -1px; }
    .pd-stage { min-height: 700px; }
    .pd-halo { width: 600px; height: 600px; margin: -300px 0 0 -300px; }
    .pd-stage { justify-items: end; }
    .phone-float { margin-right: 1.5rem; }
    .pd-ctrl { justify-content: flex-end; margin-right: 56px; }
    .pd-bignum { display: block; font-size: clamp(10rem, 15vw, 14rem); }
    .phone { width: 300px; height: 620px; }
}
.lc--static .phone-float { animation: none; }
.lc--static .pd-ch-bar { animation: none; transform: scaleX(1); }
.lc--static .ps-swap-enter-active, .lc--static .ps-swap-leave-active, .lc--static .pd-num-enter-active, .lc--static .pd-num-leave-active { transition: none; }
@media (prefers-reduced-motion: reduce) {
    .phone-float { animation: none; }
    .phone, .real-card, .pd-ch-title, .pd-ch-desc, .phone-glare { transition: none; }
}

/* ── Recursos ────────────────────────────────────────────────────────────── */
.recursos-head { display: flex; flex-direction: column; gap: 1.1rem; margin-bottom: 3rem; }
.recursos-title { font-size: clamp(2.6rem, 9vw, 5rem); }
.recursos-sub { max-width: 360px; }
.pillars { display: grid; gap: 1.1rem; align-items: start; }
.pillar { position: relative; display: flex; flex-direction: column; border-radius: 18px; overflow: hidden; background: rgba(13, 11, 9, 0.72); }
.pillar::after { content: ''; position: absolute; inset: 0; border-radius: inherit; box-shadow: inset 0 0 0 1px rgba(243, 201, 126, 0.22); pointer-events: none; transition: box-shadow 0.25s ease; }
.pillar-head { position: relative; display: block; width: 100%; aspect-ratio: 16 / 10; padding: 0; border: none; background: #0d0b09; color: inherit; font: inherit; text-align: left; cursor: pointer; overflow: hidden; -webkit-tap-highlight-color: transparent; }
.pillar-head:focus-visible { outline: 2px solid var(--gold); outline-offset: -2px; }
.pillar-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transition: transform 0.9s cubic-bezier(0.2, 0.7, 0.2, 1); }
.pillar-shade { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(13, 11, 9, 0.1) 30%, rgba(13, 11, 9, 0.92) 100%); }
.pillar-cap { position: absolute; left: 0; right: 0; bottom: 0; display: flex; align-items: center; gap: 0.8rem; padding: 1rem 1.1rem; }
.pillar-ic { width: 46px; height: 46px; border-radius: 23px 23px 4px 4px; border: 1px solid rgba(243, 201, 126, 0.6); background: rgba(13, 11, 9, 0.55); display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--gold); }
.pillar-txt { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.pillar-title { font-family: 'Cinzel', Georgia, serif; font-size: 1.3rem; letter-spacing: 0.16em; color: var(--ink); }
.pillar-sub { font-size: 0.8rem; color: var(--muted); }
.pillar-chev { margin-left: auto; width: 30px; height: 30px; border-radius: 999px; border: 1px solid rgba(243, 201, 126, 0.5); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--gold); transition: transform 0.35s ease; }
.pillar-chev svg { width: 16px; height: 16px; }
/* Lista: abre para baixo (grid 0fr → 1fr anima a altura sem JS) */
.pillar-list { display: grid; grid-template-rows: 0fr; transition: grid-template-rows 0.5s cubic-bezier(0.2, 0.7, 0.2, 1); }
.pillar-list-in { min-height: 0; overflow: hidden; padding: 0 1.1rem; }
.pillar.is-open::after { box-shadow: inset 0 0 0 1px var(--gold); }
.pillar.is-open .pillar-list { grid-template-rows: 1fr; }
.pillar.is-open .pillar-chev { transform: rotate(180deg); }
.pillar.is-open .pillar-img { transform: scale(1.05); }
@media (hover: hover) and (pointer: fine) {
    .pillar:hover::after { box-shadow: inset 0 0 0 1px var(--gold); }
    .pillar:hover .pillar-list { grid-template-rows: 1fr; }
    .pillar:hover .pillar-chev { transform: rotate(180deg); }
    .pillar:hover .pillar-img { transform: scale(1.05); }
}
@media (prefers-reduced-motion: reduce) {
    .pillar-list, .pillar-img, .pillar-chev { transition: none; }
}
.pillar-item { display: flex; flex-direction: column; gap: 4px; padding: 1rem 0; border-top: 1px solid rgba(243, 201, 126, 0.2); }
.pillar-item:first-child { border-top: none; }
.pi-row { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
.pi-name { font-size: 1.02rem; font-weight: 600; color: var(--ink); }
.pi-tier { height: 20px; padding: 0 8px; border-radius: 999px; border: 1px solid rgba(243, 201, 126, 0.5); display: inline-flex; align-items: center; font-size: 0.6rem; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--gold); }
.pi-desc { font-size: 0.92rem; line-height: 1.5; color: var(--muted); }
@media (min-width: 768px) {
    .recursos-head { flex-direction: row; align-items: flex-end; justify-content: space-between; gap: 3.4rem; }
    .pillars { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.6rem; }
    .pillar-head { aspect-ratio: 4 / 5; }
}

/* ── Círculos — acordeão de portas (mobile primeiro) ───────────────────────
   Celular: as portas empilham; a aberta cresce na vertical (figura em cima,
   painel embaixo). Desktop (≥900px): lado a lado, todas do MESMO tamanho; a
   aberta se expande na horizontal. A figura nunca é ampliada além do tamanho real
   (384×576) — no desktop ela fica à direita, em 1:1; o texto, à esquerda. */
.circulos-head { display: flex; flex-direction: column; gap: 1.4rem; margin-bottom: 2.4rem; }
.circulos-head .eyebrow { margin-bottom: 1rem; }
.circulos-aside { max-width: 400px; }
.circulos-aside .lead { margin: 0 0 1.2rem; }
.circulos-count span { display: block; font-family: var(--serif); font-size: 2.4rem; line-height: 1; color: var(--ink); }
.circulos-count small { font-size: 0.6rem; letter-spacing: 0.3em; text-transform: uppercase; color: var(--gold); }
.circle-seats { height: 26px; padding: 0 13px; border-radius: 999px; border: 1px solid rgba(243, 201, 126, 0.45); background: rgba(243, 201, 126, 0.08); display: inline-flex; align-items: center; font-size: 0.62rem; font-weight: 600; letter-spacing: 0.16em; text-transform: uppercase; color: var(--gold); }

.cdoors {
    display: flex;
    flex-direction: column;
    max-width: 1440px;
    margin-inline: auto;
    border-top: 1px solid rgba(243, 201, 126, 0.16);
    background: #080706;
}
.cdoor {
    position: relative;
    height: 84px;
    overflow: hidden;
    cursor: pointer;
    outline: none;
    border-bottom: 1px solid rgba(243, 201, 126, 0.16);
    -webkit-tap-highlight-color: transparent;
    transition: height 0.9s cubic-bezier(0.22, 1, 0.36, 1);
}
.cdoor.is-open { height: 660px; }
.cdoor:focus-visible { box-shadow: inset 0 0 0 2px var(--gold); }
/* Linha de luz no topo da porta aberta. */
.cdoor::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    z-index: 3;
    width: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, #fff3cf, var(--gold), transparent);
    transition: width 1.1s cubic-bezier(0.22, 1, 0.36, 1);
}
.cdoor.is-open::after { width: 100%; }

.cdoor-fig { position: absolute; inset: 0; display: block; }
.cdoor-fig img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    filter: grayscale(0.9) brightness(0.45);
    transition: filter 1s ease;
}
.cdoor.is-open .cdoor-fig { inset: 0 0 auto 0; height: 44%; }
.cdoor.is-open .cdoor-fig img {
    filter: none;
    -webkit-mask-image: linear-gradient(180deg, #000 55%, transparent 100%);
    mask-image: linear-gradient(180deg, #000 55%, transparent 100%);
}
.cdoor-shade { position: absolute; inset: 0; pointer-events: none; background: linear-gradient(90deg, rgba(8, 7, 6, 0.85) 0%, rgba(8, 7, 6, 0.45) 100%); transition: opacity 0.6s ease; }
.cdoor.is-open .cdoor-shade { opacity: 0; }

/* Fechada */
.cdoor-closed {
    position: absolute;
    inset: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    gap: 0.9rem;
    padding: 0 1.3rem;
    transition: opacity 0.45s ease, transform 0.6s ease;
}
.cdoor-num { font-size: 0.62rem; letter-spacing: 0.3em; color: var(--gold); }
.cdoor-name { font-family: var(--disp); font-size: 1rem; letter-spacing: 0.18em; color: var(--ink); white-space: nowrap; }
.cdoor-mini { margin-left: auto; font-size: 0.78rem; letter-spacing: 0.04em; color: var(--muted); white-space: nowrap; }
.cdoor.is-open .cdoor-closed { opacity: 0; transform: translateY(14px); pointer-events: none; }

/* Aberta */
.cdoor-open {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 2;
    padding: 0 1.3rem 1.6rem;
    opacity: 0;
    transform: translateY(20px);
    pointer-events: none;
    transition: opacity 0.4s ease, transform 0.6s ease;
}
.cdoor.is-open .cdoor-open {
    opacity: 1;
    transform: none;
    pointer-events: auto;
    transition: opacity 0.7s ease 0.35s, transform 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.3s;
}
.cdoor-kicker { display: block; margin-bottom: 0.55rem; font-size: 0.6rem; font-weight: 600; letter-spacing: 0.24em; text-transform: uppercase; color: var(--gold); }
.cdoor-title { margin: 0 0 0.3rem; font-family: var(--disp); font-weight: 400; font-size: clamp(1.7rem, 7vw, 2.6rem); letter-spacing: 0.1em; line-height: 1; color: var(--ink); }
.cdoor-reveal { display: grid; grid-template-rows: 0fr; transition: grid-template-rows 0.9s cubic-bezier(0.22, 1, 0.36, 1); }
.cdoor.is-open .cdoor-reveal { grid-template-rows: 1fr; transition-delay: 0.25s; }
.cdoor-reveal > div { overflow: hidden; }
.cdoor-price { margin: 0.7rem 0 0.7rem; font-family: var(--serif); font-size: 2.1rem; line-height: 1; color: var(--gold); }
.cdoor-price span { margin-left: 2px; font-family: var(--body); font-size: 0.78rem; color: var(--muted); }
.cdoor-perks { list-style: none; margin: 0 0 1rem; padding: 0; }
.cdoor-perks li { display: flex; align-items: center; gap: 0.75rem; padding: 0.42rem 0; border-bottom: 1px solid rgba(243, 201, 126, 0.12); font-size: 0.86rem; line-height: 1.4; color: var(--muted); }
.cdoor-perks li::before { content: ''; flex: none; width: 5px; height: 5px; transform: rotate(45deg); background: var(--gold); }
.cdoor-perks b { font-weight: 600; color: var(--ink); }
.cdoor-open .circle-seats { margin-bottom: 1rem; }
.cdoor-open .circle-seats + .cdoor-cta { margin-top: 0; }
/* CTA com o raio girando na borda — leva à lista de fundadores. */
.cdoor-cta {
    position: relative;
    isolation: isolate;
    display: flex;
    align-items: center;
    gap: 0.9rem;
    min-height: 48px;
    padding: 0 1.5rem;
    border: 1px solid rgba(243, 201, 126, 0.4);
    border-radius: 999px;
    background: #120e0a;
    color: var(--ink);
    font-family: var(--body);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    cursor: pointer;
    transition: color 0.3s ease, gap 0.3s ease, border-color 0.3s ease;
}
.cdoor-cta::before {
    content: '';
    position: absolute;
    inset: -1px;
    z-index: -1;
    border-radius: inherit;
    padding: 1.5px;
    background: conic-gradient(from var(--lc-ray), transparent 0deg, transparent 260deg, var(--gold) 320deg, #fff3cf 345deg, transparent 360deg);
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor;
    mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    mask-composite: exclude;
    animation: lc-ray 2.4s linear infinite;
}
.cdoor-cta-arrow { color: var(--gold); transition: transform 0.3s ease; }
.cdoor-cta:hover, .cdoor-cta:focus-visible { color: var(--gold); gap: 1.3rem; border-color: rgba(243, 201, 126, 0.7); outline: none; }
.cdoor-cta:hover .cdoor-cta-arrow { transform: translateX(3px); }

/* Surgimento lento ao rolar (o v-reveal só põe .rv quando vai conseguir revelar).
   Seletor mais específico que o .rv genérico para não perder a transição de abrir. */
.cdoors.rv .cdoor { opacity: 0; transform: translateY(50px); }
.cdoors.rv.is-in .cdoor {
    opacity: 1;
    transform: none;
    transition:
        opacity 1.6s ease calc(var(--i, 0) * 260ms),
        transform 1.6s cubic-bezier(0.16, 0.84, 0.3, 1) calc(var(--i, 0) * 260ms),
        height 0.9s cubic-bezier(0.22, 1, 0.36, 1),
        flex 0.9s cubic-bezier(0.22, 1, 0.36, 1);
}

@media (min-width: 768px) {
    .circulos-head { flex-direction: row; align-items: flex-end; justify-content: space-between; gap: 3rem; margin-bottom: 3.6rem; }
}
@media (min-width: 900px) {
    .cdoors { flex-direction: row; height: 576px; border-bottom: 1px solid rgba(243, 201, 126, 0.16); }
    .cdoor { flex: 1 1 0; min-width: 0; height: auto; border-bottom: 0; border-right: 1px solid rgba(243, 201, 126, 0.14); transition: flex 0.9s cubic-bezier(0.22, 1, 0.36, 1); }
    .cdoor:last-child { border-right: 0; }
    .cdoor.is-open { flex: 3.4 1 0; height: auto; }
    .cdoor-fig, .cdoor.is-open .cdoor-fig { inset: 0 0 0 auto; width: min(100%, 384px); height: 100%; }
    .cdoor.is-open .cdoor-fig img {
        -webkit-mask-image: linear-gradient(90deg, transparent 0%, #000 35%);
        mask-image: linear-gradient(90deg, transparent 0%, #000 35%);
    }
    .cdoor-shade { background: linear-gradient(180deg, rgba(8, 7, 6, 0.35) 0%, transparent 25%, transparent 45%, rgba(8, 7, 6, 0.92) 82%, #080706 100%); }
    .cdoor-closed { inset: auto 0 0 0; flex-direction: column; align-items: flex-start; gap: 0.5rem; padding: 0 1.4rem 1.6rem; }
    .cdoor-name { font-size: 1rem; }
    .cdoor-mini { margin-left: 0; }
    .cdoor-open { right: auto; top: 50%; bottom: auto; width: min(62%, 440px); padding: 0 0 0 2.2rem; transform: translateY(-46%); }
    .cdoor.is-open .cdoor-open { transform: translateY(-50%); }
}
/* Faixa intermediária: os nomes das portas fechadas ficam na vertical. */
@media (min-width: 900px) and (max-width: 1099px) {
    .cdoor-closed .cdoor-name { writing-mode: vertical-rl; transform: rotate(180deg); }
    .cdoor-closed .cdoor-mini { display: none; }
    .cdoor-open { width: min(70%, 400px); padding-left: 1.4rem; }
}
@media (prefers-reduced-motion: reduce) {
    .cdoor, .cdoor-fig img, .cdoor-open, .cdoor-closed, .cdoor-reveal, .cdoor::after { transition: none; }
    .cdoor-cta::before { animation: none; }
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
.f-check a { color: var(--gold); text-decoration: underline; }

.role-toggle, .world-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem; }
.world-sub { margin-top: 1rem; }
.role-opt, .world-opt {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    min-height: 48px;
    padding: 0.5rem 0.8rem;
    text-align: center;
    line-height: 1.25;
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
.lc-foot-social { margin-top: 1.5rem; display: flex; flex-wrap: wrap; gap: 1.25rem; }
.lc-foot-social a { color: var(--gold-soft); display: inline-flex; transition: color .2s, transform .2s; }
.lc-foot-social a:hover { color: var(--gold); transform: translateY(-2px); }
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
    .portal-sticky { position: relative; height: auto; min-height: 88svh; padding: 20svh 0; }
    .portal-media, .portal-glow { animation: none; }
    .lc-dust { display: none; }
    .real-card::before, .real-rule::after { animation: none !important; }
}
</style>
