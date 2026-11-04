<script setup>
import { computed, ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { postJson, errorMessage } from '@/lib/http'
import qrcode from '@/vendor/qrcode'

const props = defineProps({
    enabled: { type: Boolean, default: false },
    // Mesma fonte da tela de pós-cadastro (SignupWelcomeData): papel, bônus,
    // contadores e o link padrão (?ref=).
    welcome: { type: Object, required: true },
    custom_links: { type: Array, default: () => [] },
    custom_links_max: { type: Number, default: 2 },
    avatar_url: { type: String, default: null },
})

const isPerformer = computed(() => props.welcome.role === 'performer')
const bonuses = computed(() => props.welcome.bonuses)
const counters = computed(() => props.welcome.counters)

// Links: o padrão (?ref=) + os personalizados criados nesta sessão.
const customLinks = ref([...props.custom_links])
const defaultLink = computed(() => props.welcome.referral_link || '')
const primaryLink = computed(() => customLinks.value[0]?.url || defaultLink.value)
const linkHost = computed(() => {
    try { return new URL(primaryLink.value || window.location.href).host } catch { return 'thelimen.com.br' }
})

const copied = ref(null)
async function copy(value, key) {
    try {
        await navigator.clipboard.writeText(value)
        copied.value = key
        setTimeout(() => { if (copied.value === key) copied.value = null }, 2000)
    } catch { /* contexto sem clipboard: seleciona e copia à mão */ }
}

async function shareMain() {
    const text = isPerformer.value
        ? 'Estou no Limen como criadora. Entra pelo meu link:'
        : 'Entra no Limen pelo meu link de indicação:'
    if (!navigator.share) { copy(primaryLink.value, 'primary'); return }
    try { await navigator.share({ title: 'Limen', text, url: primaryLink.value }) } catch { /* cancelado */ }
}

// ── Modal "Personalizar link" ────────────────────────────────────────────────
// Passos: list → create → confirm. Links são IMUTÁVEIS (aviso no confirm).
const linkModal = ref(null) // null | 'list' | 'create' | 'confirm'
const slug = ref('')
const slugError = ref('')
const savingLink = ref(false)
const atMax = computed(() => customLinks.value.length >= props.custom_links_max)
const slugClean = computed(() => slug.value.toLowerCase().trim())
const charsLeft = computed(() => Math.max(0, 20 - slugClean.value.length))
const slugLooksValid = computed(() => /^[a-z0-9][a-z0-9-]{1,18}[a-z0-9]$/.test(slugClean.value))

function openLinkModal() {
    slug.value = ''
    slugError.value = ''
    linkModal.value = customLinks.value.length ? 'list' : 'create'
}
function toCreate() { slug.value = ''; slugError.value = ''; linkModal.value = 'create' }
function toConfirm() {
    slugError.value = ''
    if (!slugLooksValid.value) {
        slugError.value = 'Use de 3 a 20 caracteres: letras minúsculas, números e hífen.'
        return
    }
    linkModal.value = 'confirm'
}
async function saveLink() {
    savingLink.value = true
    try {
        const data = await postJson(route('referral.links.store'), { slug: slugClean.value })
        customLinks.value.push(data.link)
        linkModal.value = 'list'
        slug.value = ''
    } catch (e) {
        slugError.value = errorMessage(e, 'Não foi possível criar o link.')
        linkModal.value = 'create'
    } finally {
        savingLink.value = false
    }
}

// ── Modal QR ─────────────────────────────────────────────────────────────────
// Gerado no navegador com a lib vendorizada (MIT, self-host — nada de CDN).
// Correção de erro H: tolera o centro coberto pela foto, como no Airbnb.
const qrOpen = ref(false)
const showPhoto = ref(true)
const qr = computed(() => {
    try {
        const q = qrcode(0, 'H')
        q.addData(primaryLink.value)
        q.make()
        const n = q.getModuleCount()
        const cells = []
        for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (q.isDark(r, c)) cells.push(c * n + r)
        return { n, cells }
    } catch { return { n: 0, cells: [] } }
})
const hasPhoto = computed(() => Boolean(props.avatar_url) && showPhoto.value)

function drawQrCanvas() {
    return new Promise((resolve) => {
        const { n, cells } = qr.value
        const scale = 24, quiet = 4 * scale, size = n * scale + quiet * 2
        const canvas = document.createElement('canvas')
        canvas.width = canvas.height = size
        const ctx = canvas.getContext('2d')
        ctx.fillStyle = '#f6f1e6'; ctx.fillRect(0, 0, size, size)
        ctx.fillStyle = '#1c160f'
        const set = new Set(cells)
        for (let c = 0; c < n; c++) for (let r = 0; r < n; r++) {
            if (set.has(c * n + r)) ctx.fillRect(quiet + c * scale, quiet + r * scale, scale, scale)
        }
        const cx = size / 2, radius = n * scale * 0.16
        const finishCenter = (drawInner) => {
            ctx.fillStyle = '#ffffff'
            ctx.beginPath(); ctx.arc(cx, cx, radius + scale * 0.6, 0, Math.PI * 2); ctx.fill()
            drawInner()
            resolve(canvas)
        }
        if (hasPhoto.value) {
            const img = new Image()
            img.onload = () => finishCenter(() => {
                ctx.save()
                ctx.beginPath(); ctx.arc(cx, cx, radius, 0, Math.PI * 2); ctx.clip()
                ctx.drawImage(img, cx - radius, cx - radius, radius * 2, radius * 2)
                ctx.restore()
            })
            img.onerror = () => finishCenter(() => drawDoor(ctx, cx, radius))
            img.src = props.avatar_url
        } else {
            finishCenter(() => drawDoor(ctx, cx, radius))
        }
    })
}
function drawDoor(ctx, cx, radius) {
    ctx.strokeStyle = '#b8964f'
    ctx.lineWidth = radius * 0.12
    const w = radius * 1.05, h = radius * 1.3
    ctx.beginPath()
    ctx.moveTo(cx - w / 2, cx + h / 2)
    ctx.lineTo(cx - w / 2, cx - h * 0.1)
    ctx.arc(cx, cx - h * 0.1, w / 2, Math.PI, 0)
    ctx.lineTo(cx + w / 2, cx + h / 2)
    ctx.stroke()
}
async function saveQr() {
    const canvas = await drawQrCanvas()
    const a = document.createElement('a')
    a.href = canvas.toDataURL('image/png')
    a.download = 'limen-indicacao.png'
    a.click()
}
async function shareQr() {
    try {
        const canvas = await drawQrCanvas()
        const blob = await new Promise((r) => canvas.toBlob(r, 'image/png'))
        const file = new File([blob], 'limen-indicacao.png', { type: 'image/png' })
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            await navigator.share({ files: [file], title: 'Limen', url: primaryLink.value })
            return
        }
    } catch { /* cai no share de link */ }
    shareMain()
}
</script>

<template>
    <AppLayout title="Indique e ganhe">
        <div class="mx-auto w-full max-w-md px-5 pb-16 text-limen-ink">

            <!-- CABEÇALHO -->
            <div class="relative mt-6 overflow-hidden rounded-2xl border border-limen-line" style="background: radial-gradient(120% 80% at 50% 115%, rgba(240,217,154,0.22), rgba(24,20,16,0) 60%), linear-gradient(180deg, #211b14, #181410);">
                <div class="flex flex-col items-center gap-3 px-6 pb-7 pt-8 text-center">
                    <svg width="44" height="58" viewBox="0 0 120 150" fill="none" aria-hidden="true">
                        <path d="M18 150 V54 Q18 14 60 14 Q102 14 102 54 V150" stroke="#d6b872" stroke-width="2.5" fill="none" />
                        <path d="M30 150 V58 Q30 26 60 26 Q90 26 90 58 V150 Z" fill="#161008" />
                        <circle cx="79" cy="92" r="2.4" fill="#f0d99a" />
                    </svg>
                    <div>
                        <div class="text-xs font-semibold tracking-[0.18em] text-limen-gold">PROGRAMA DE INDICAÇÃO</div>
                        <h1 class="mt-2 font-serif text-3xl leading-tight">{{ isPerformer ? 'Indique criadoras. Ganhe tokens.' : 'Indique amigos. Ganhe tokens.' }}</h1>
                    </div>
                </div>
            </div>

            <div v-if="!enabled" class="mt-4 rounded-xl border border-limen-line bg-limen-surface px-4 py-3 text-sm text-limen-ink-mute">
                O programa ainda não está ativo. Guarde seus links — em breve eles valerão tokens.
            </div>

            <p class="mt-5 text-limen-ink-soft">Cada pessoa que entra <strong class="text-limen-ink">de verdade</strong> pelo seu link rende tokens para os dois lados. Sem cadastro fantasma.</p>

            <!-- RECOMPENSAS (papel-aware, números do config) -->
            <div class="mt-4 rounded-2xl border border-limen-gold bg-limen-surface p-4">
                <div class="flex items-center justify-between gap-2">
                    <div class="font-bold">{{ isPerformer ? 'Criadora indicada se verifica e tem o 1º ganho' : 'Seu amigo entra e faz a 1ª compra' }}</div>
                    <span v-if="isPerformer" class="shrink-0 rounded-full bg-limen-gold px-2 py-0.5 text-[11px] font-bold tracking-wide text-limen-bg">MAIOR BÔNUS</span>
                </div>
                <div class="mt-3 flex gap-2.5">
                    <div class="flex-1 rounded-xl bg-limen-surface-2 p-3">
                        <div class="text-xs text-limen-ink-mute">Você ganha</div>
                        <div class="font-serif text-2xl text-limen-gold">+{{ isPerformer ? bonuses.performer_first_earning.referrer : bonuses.member_purchase.referrer }} <span class="font-sans text-xs text-limen-ink-soft">tokens</span></div>
                    </div>
                    <div class="flex-1 rounded-xl bg-limen-surface-2 p-3">
                        <div class="text-xs text-limen-ink-mute">{{ isPerformer ? 'Ela ganha' : 'Seu amigo ganha' }}</div>
                        <div class="font-serif text-2xl text-limen-gold">+{{ isPerformer ? bonuses.performer_first_earning.referred : bonuses.member_purchase.referred }} <span class="font-sans text-xs text-limen-ink-soft">tokens</span></div>
                    </div>
                </div>
            </div>
            <div class="mt-3 flex items-center justify-between gap-3 rounded-2xl border border-limen-line bg-limen-surface px-4 py-3.5">
                <div class="text-sm text-limen-ink-soft"><strong class="text-limen-ink">Bônus extra:</strong> {{ isPerformer ? 'um fã que você chamar vira membro e faz a 1ª compra' : 'uma criadora que você indicar se verifica e tem o 1º ganho' }}</div>
                <div class="shrink-0 font-serif text-xl text-limen-gold">+{{ isPerformer ? bonuses.member_purchase.referrer : bonuses.performer_first_earning.referrer }}</div>
            </div>

            <!-- CTA + QR + PERSONALIZAR -->
            <button type="button" class="mt-5 min-h-[54px] w-full rounded-xl bg-limen-gold text-base font-extrabold text-limen-bg" @click="shareMain">Compartilhar link de indicação</button>
            <div class="mt-2.5 flex gap-2.5">
                <button type="button" aria-label="Meu QR code" class="flex min-h-[50px] w-[54px] shrink-0 items-center justify-center rounded-xl border border-limen-line bg-limen-surface" @click="qrOpen = true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v2M14 20h2M18 18h3v3h-3z"/></svg>
                </button>
                <button type="button" class="flex min-h-[50px] flex-1 items-center justify-center gap-2 rounded-xl border border-limen-line bg-limen-surface text-sm font-bold text-limen-ink" @click="openLinkModal">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l4.5-1L19 8.5a2.1 2.1 0 0 0-3-3L5.5 16z"/><path d="M13.5 7.5l3 3"/></svg>
                    Personalizar link
                </button>
            </div>

            <!-- SEUS LINKS -->
            <div class="mt-6 flex flex-col gap-3.5 rounded-2xl border border-limen-line bg-limen-surface p-4">
                <div class="font-serif text-lg">Seus links</div>

                <div>
                    <div class="mb-1.5 text-xs text-limen-ink-mute">Link padrão</div>
                    <div class="flex items-center gap-2 rounded-lg border border-limen-line bg-limen-bg py-1 pl-3 pr-1">
                        <span class="min-w-0 flex-1 truncate text-sm">{{ defaultLink }}</span>
                        <button type="button" class="min-h-[40px] shrink-0 rounded-lg bg-limen-surface-2 px-3.5 text-[13px] font-bold text-limen-gold" @click="copy(defaultLink, 'default')">{{ copied === 'default' ? 'Copiado' : 'Copiar' }}</button>
                    </div>
                </div>

                <div>
                    <div class="mb-1.5 text-xs text-limen-ink-mute">Links personalizados · {{ customLinks.length }} de {{ custom_links_max }} criados</div>
                    <div v-for="l in customLinks" :key="l.slug" class="mb-2 flex items-center gap-2 rounded-lg border border-limen-gold bg-limen-bg py-1 pl-3 pr-1">
                        <span class="min-w-0 flex-1 truncate text-sm">{{ linkHost }}<strong class="text-limen-gold">/r/{{ l.slug }}</strong></span>
                        <button type="button" class="min-h-[40px] shrink-0 rounded-lg bg-limen-surface-2 px-3.5 text-[13px] font-bold text-limen-gold" @click="copy(l.url, l.slug)">{{ copied === l.slug ? 'Copiado' : 'Copiar' }}</button>
                    </div>
                    <button type="button" class="flex min-h-[46px] w-full items-center justify-center gap-2 rounded-lg border border-dashed border-limen-line text-sm font-semibold text-limen-ink-soft disabled:opacity-50" :disabled="atMax" @click="openLinkModal">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        {{ atMax ? 'Limite de links atingido' : 'Criar link personalizado' }}
                    </button>
                </div>
                <p class="text-xs text-limen-ink-mute">Links personalizados são seus para sempre: <strong class="text-limen-ink-soft">não podem ser alterados nem excluídos</strong> depois de criados.</p>
            </div>

            <!-- CONTADORES -->
            <div class="mt-4 flex gap-2.5">
                <div class="flex-1 rounded-xl border border-limen-line bg-limen-surface p-3 text-center"><div class="font-serif text-2xl">{{ counters.sent }}</div><div class="text-xs text-limen-ink-mute">convites feitos</div></div>
                <div class="flex-1 rounded-xl border border-limen-line bg-limen-surface p-3 text-center"><div class="font-serif text-2xl">{{ counters.confirmed }}</div><div class="text-xs text-limen-ink-mute">confirmaram</div></div>
                <div class="flex-1 rounded-xl border border-limen-line bg-limen-surface p-3 text-center"><div class="font-serif text-2xl text-limen-gold">{{ counters.pending_tokens }}</div><div class="text-xs text-limen-ink-mute">tokens a receber</div></div>
            </div>

            <p class="mt-4 border-t border-limen-line pt-3 text-xs text-limen-ink-mute">
                Bônus em tokens: crédito de uso dentro do Limen — não é dinheiro e não pode ser sacado. Liberado quando a indicação converte de verdade, com e-mail confirmado e janela de segurança de 14 dias.
            </p>
        </div>

        <!-- MODAL: PERSONALIZAR LINK -->
        <div v-if="linkModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-5" @click.self="linkModal = null">
            <div class="w-full max-w-sm rounded-2xl border border-limen-line bg-limen-surface p-6 text-limen-ink">

                <template v-if="linkModal === 'list'">
                    <h2 class="text-center font-serif text-xl">Links personalizados</h2>
                    <p class="mt-1 text-center text-sm text-limen-ink-mute">Crie até {{ custom_links_max }} links de indicação com a sua cara.</p>
                    <div v-for="l in customLinks" :key="l.slug" class="mt-3 flex items-center justify-between gap-2 rounded-xl border border-limen-line bg-limen-bg px-3.5 py-3">
                        <div><div class="text-xs text-limen-ink-mute">{{ linkHost }}/r/</div><div class="font-bold">{{ l.slug }}</div></div>
                        <button type="button" class="min-h-[40px] rounded-lg bg-limen-surface-2 px-3 text-[13px] font-bold text-limen-gold" @click="copy(l.url, 'm-' + l.slug)">{{ copied === 'm-' + l.slug ? 'Copiado' : 'Copiar' }}</button>
                    </div>
                    <button v-if="!atMax" type="button" class="mt-3 flex min-h-[48px] w-full items-center justify-between rounded-xl border border-dashed border-limen-line px-4 font-semibold text-limen-ink-soft" @click="toCreate">
                        Crie um link
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#d6b872" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                    <button type="button" class="mt-4 min-h-[44px] w-full text-sm text-limen-ink-mute underline underline-offset-4" @click="linkModal = null">Fechar</button>
                </template>

                <template v-else-if="linkModal === 'create'">
                    <h2 class="text-center font-serif text-xl">Crie um link</h2>
                    <div class="mt-4 text-center">
                        <div class="text-lg text-limen-ink-mute">{{ linkHost }}/r/</div>
                        <input v-model="slug" maxlength="20" autocapitalize="none" autocomplete="off" spellcheck="false"
                            class="mt-1 w-full border-0 border-b-2 border-limen-gold bg-transparent text-center text-xl font-bold text-limen-ink outline-none"
                            placeholder="seu-nome" @keyup.enter="toConfirm" />
                        <div class="mt-2 text-[13px] text-limen-ink-mute"><strong class="text-limen-ink-soft">{{ charsLeft }}</strong> caracteres disponíveis</div>
                    </div>
                    <p class="mt-3 text-center text-xs text-limen-ink-mute">3–20 caracteres: letras minúsculas, números e hífen.<br>Nomes de criadoras e termos reservados não são aceitos.</p>
                    <p v-if="slugError" class="mt-3 rounded-xl border border-[#7a4438] bg-[#2a1c18] px-3.5 py-2.5 text-sm text-[#ecd9d2]">{{ slugError }}</p>
                    <div class="mt-5 flex items-center justify-between">
                        <button type="button" class="min-h-[44px] text-sm text-limen-ink-soft underline underline-offset-4" @click="linkModal = null">Cancelar</button>
                        <button type="button" class="min-h-[46px] rounded-xl px-6 font-extrabold disabled:opacity-60"
                            :class="slugLooksValid ? 'bg-limen-gold text-limen-bg' : 'bg-limen-surface-2 text-limen-ink-mute'"
                            :disabled="!slugClean" @click="toConfirm">Salvar</button>
                    </div>
                </template>

                <template v-else>
                    <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-limen-gold text-xl font-extrabold text-limen-bg">!</div>
                    <h2 class="mt-3 text-center font-serif text-xl">Criar este link?</h2>
                    <p class="mt-1 text-center text-sm text-limen-ink-mute">Links personalizados não podem ser alterados nem excluídos depois de criados.</p>
                    <div class="mt-4 rounded-xl border border-limen-line bg-limen-bg px-3 py-3 text-center font-bold">{{ linkHost }}<span class="text-limen-gold">/r/{{ slugClean }}</span></div>
                    <div class="mt-5 flex gap-2.5">
                        <button type="button" class="min-h-[48px] flex-1 rounded-xl border border-limen-line font-bold text-limen-ink" @click="linkModal = 'create'">Editar</button>
                        <button type="button" class="min-h-[48px] flex-1 rounded-xl bg-limen-gold font-extrabold text-limen-bg disabled:opacity-60" :disabled="savingLink" @click="saveLink">{{ savingLink ? 'Salvando…' : 'Salvar' }}</button>
                    </div>
                </template>
            </div>
        </div>

        <!-- MODAL: QR CODE -->
        <div v-if="qrOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-5" @click.self="qrOpen = false">
            <div class="w-full max-w-sm rounded-2xl border border-limen-line bg-limen-surface p-6 text-limen-ink">
                <h2 class="text-center font-serif text-xl">Seu código de indicação</h2>
                <p class="mt-1 text-center text-sm text-limen-ink-mute">Peça para a pessoa apontar a câmera do celular.</p>

                <div class="relative mx-auto mt-4 w-fit rounded-3xl bg-[#f6f1e6] p-4 shadow-xl">
                    <svg :viewBox="`0 0 ${qr.n} ${qr.n}`" width="216" height="216" shape-rendering="crispEdges" aria-label="QR code do seu link de indicação">
                        <rect v-for="cell in qr.cells" :key="cell" :x="Math.floor(cell / qr.n)" :y="cell % qr.n" width="1" height="1" fill="#1c160f" />
                    </svg>
                    <div class="absolute left-1/2 top-1/2 flex h-[72px] w-[72px] -translate-x-1/2 -translate-y-1/2 items-center justify-center overflow-hidden rounded-full border-4 border-white bg-white">
                        <img v-if="hasPhoto" :src="avatar_url" alt="" class="h-full w-full object-cover" />
                        <svg v-else width="34" height="44" viewBox="0 0 120 150" fill="none" aria-hidden="true"><path d="M18 150 V54 Q18 14 60 14 Q102 14 102 54 V150" stroke="#b8964f" stroke-width="10" fill="none" /></svg>
                    </div>
                </div>
                <div class="mt-2.5 text-center text-[13px] text-limen-ink-mute">{{ primaryLink }}</div>

                <label v-if="avatar_url" class="mt-3 flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-limen-line bg-limen-bg px-3.5 py-3">
                    <span class="text-sm text-limen-ink-soft">Mostrar minha foto no centro</span>
                    <input v-model="showPhoto" type="checkbox" class="h-5 w-5" style="accent-color:#d6b872" />
                </label>
                <p class="mt-2 text-center text-xs text-limen-ink-mute">Sem foto, o centro mostra o símbolo do Portal.</p>

                <div class="mt-4 flex flex-col gap-2">
                    <button type="button" class="min-h-[48px] w-full rounded-xl bg-limen-gold font-extrabold text-limen-bg" @click="saveQr">Salvar</button>
                    <button type="button" class="min-h-[48px] w-full rounded-xl border border-limen-line font-bold text-limen-ink" @click="shareQr">Compartilhar</button>
                    <button type="button" class="min-h-[40px] w-full text-sm text-limen-ink-mute underline underline-offset-4" @click="qrOpen = false">Fechar</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
