# Spec (PROPOSTA) — Chamada 1:1 com presença online

> **Status: RASCUNHO / A CONFIRMAR pelo PO.** Nasce do UAT da Fase 9.2 (08/10/2026).
> Nada implementado ainda além dos fixes de UI do modal. Este doc fixa o MODELO
> antes do código — confirme/corrija as regras marcadas com ❓ antes do build.

## Problema que resolve

Hoje a chamada privada só existe **"a partir da live"**: o aviso de chamada
(`CallIncoming`) está montado **só no console da live** (`LiveRoom.vue`). Mas o
botão **"Pedir chamada privada"** aparece no perfil **sempre** que a performer tem
preço de chamada, sem checar nada. Resultado (achado do UAT #91): o membro pede, o
pedido é criado, mas **se a performer não estiver transmitindo, ninguém escuta** → a
performer nunca vê e o pedido expira em 60s.

## Modelo desejado (visão do PO)

A performer **logada no site** (sem live pública, sem outra chamada) pode receber um
pedido de **chamada 1:1 privada**. Ela aceita → abre uma transmissão **1:1 e
INVISÍVEL**: ninguém no catálogo sabe que ela está "ao vivo" nem em chamada. É só
entre ela e aquele membro. A live pública e a chamada 1:1 são **modos distintos**: a
performer pode sair da 1:1 e, se quiser, ir para a live pública (aí sim todos veem).

## Estados da performer (do ponto de vista do membro) ❓CONFIRMAR

| Estado | Como o membro vê | "Pedir chamada privada" |
|---|---|---|
| **Offline** (não logada, ou `appear_offline`) | sem presença | **oculto** (só "Agendar chamada") |
| **Online/disponível** (logada, sem live, sem chamada) | "Online agora" (pontinho verde, já existe via `is_available`) | **DISPONÍVEL** ← novo |
| **Em live pública** (`is_live`) | "AO VIVO" no catálogo | ❓ **a confirmar:** manter o caminho atual (pedir chamada de DENTRO da live, que pausa a live) e **ocultar** o botão de chamada avulsa no perfil? |
| **Ocupada em chamada 1:1** | aparece como online normal (a 1:1 é invisível) | **oculto/indisponível** (ela está ocupada) |

> ❓ **Ponto que o PO precisa cravar:** a sua descrição no chat ficou ambígua ("só
> disponível quando ela não está em nenhuma chamada nem 1:1 e nem online" vs
> "disponível quando ela está logada apenas"). A leitura que proponho acima é:
> **o botão aparece quando a performer está LOGADA/disponível e LIVRE** (sem
> chamada e sem live pública). Confirme se é isso, ou se durante a live pública o
> botão de chamada avulsa também deve aparecer.

## Invariantes de privacidade (não negociáveis)

- A chamada 1:1 **NÃO liga `is_live`** e **não entra no catálogo / trilha "Agora"**:
  ninguém além dos dois participantes sabe que ela acontece.
- A performer vê o membro só por **FanAlias** (nunca id/nome/saldo/tier — M.13.10),
  como já é hoje.
- "Ocupada em 1:1" **não vaza** para outros membros como estado novo — para o resto
  do mundo ela continua "online" (ou o botão some, mas sem explicar o porquê).
- `appear_offline` (Ghost/Discreto) continua respeitado: quem optou por não aparecer
  online não recebe pedido de chamada avulsa.

## As 4 peças a construir

1. **Gate do botão (membro):** em `Catalog/Show.vue`, trocar a condição atual
   (`call_enabled && call_price_per_minute`) por **`+ performer.is_available`
   (online) + não-ocupada**. Fora disso, mostra só **Agendar chamada**.
2. **`CallIncoming` global:** mover/montar o `CallIncoming` no **AppLayout** (ou um
   host logado), para a performer receber o pedido **em qualquer tela logada**, não
   só no console da live. (Guard: performer não-ocupada; respeita `appear_offline`.)
3. **Host de chamada 1:1 avulsa:** hoje o aceite → `PrivateCall` só acontece dentro
   do `LiveRoom`. Precisa de um **host standalone** (uma rota/overlay logado) que
   monte a `PrivateCall role="performer"` **sem** passar por uma live pública e
   **sem** setar `is_live`. O lado do membro já tem `PrivateCall` fora da live
   (via `CallRequest` → `onCallAccepted`); falta o lado da performer.
4. **Estado "ocupada" exposto ao gate:** o `CallService` já sabe `memberIsBusy` e
   trava a performer ocupada (`occupying()`); expor um `performer.busy_in_call`
   (derivado, sem vazar com quem) para o gate do botão (#1) e para o `CallIncoming`
   não tocar durante outra chamada.

**Mantém-se:** a chamada **de dentro da live pública** (que pausa a live para os
viewers — `#95.3/#95.4`) continua funcionando como hoje; os dois caminhos convivem.

## Invariantes de economia (não mudam)

A cobrança por minuto (`floor(t/60)+1`), o split **70/30** (`call_credit`,
`applied_rate=70`), o débito do 1º minuto no aceite e a idempotência por
`minutes_billed` **permanecem iguais** — isso já foi validado no UAT (membro −10,
performer +7 @ 70%). Esta feature é só **presença + roteamento do aviso**, não mexe
no ledger.

## Fases sugeridas

- **Fase 1 (destrava + corrige o achado do UAT):** #1 gate do botão por online +
  #4 estado "ocupada". Efeito imediato: o botão só aparece quando faz sentido, acaba
  o "Aguardando pra sempre". (A chamada em si segue pelo caminho da live por ora.)
- **Fase 2 (realiza a visão):** #2 `CallIncoming` global + #3 host da 1:1 avulsa →
  a performer logada recebe e atende a 1:1 sem precisar abrir live pública.
- **Fase 3 (polimento):** toggle explícito "sair da 1:1 → ir para live pública",
  sons/indicadores, e revisão de segurança (um subagente, por mexer em canal global
  e presença).

## Já feito nesta rodada (fora do spec)

Fix de UI do modal `CallRequest` (tema escuro, título visível, botões com
contraste/espaço e alvos ≥44px; o gatilho deixou de usar `bg-brand` inexistente).

## Decisões pendentes do PO antes do build

1. Confirmar a tabela de estados acima (o ❓ da live pública).
2. Fase 1 agora, ou esperar a decisão do PSP/jurídico e fazer tudo junto? (Lembrando:
   isto **não** depende do processador de pagamento — é só presença/UX.)
