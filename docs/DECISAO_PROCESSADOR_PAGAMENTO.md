# Decisão — Processador de pagamento (substituto do Asaas)

> **Status:** em aberto (aguardando aceites por escrito dos PSPs). Documento vivo:
> registra o que **sabemos** em 08/10/2026 para confrontar com informação nova.
> Nada aqui ainda está contratado. Fonte de pesquisa: relatório "Processadores PIX
> para plataforma adulta" (sessão de 08/10/2026).

## Contexto — por que sair do Asaas

- **Asaas vetou o ramo.** O próprio canal oficial do Asaas (chat da conta sandbox
  `admin.limen@gmail.com`) respondeu que **não aceita pornografia/marketplace de
  conteúdo adulto**. É instituição de pagamento autorizada (Banco 461 — Asaas IP
  S.A.), mas a recusa é de **política comercial**, não regulatória. → Asaas está
  **fora** do go-live.
- **Stripe está DESCARTADO.** A lista oficial de *Prohibited Businesses* do Stripe
  veta conteúdo adulto **sem caminho de aprovação** ("Pornography and other mature
  audience content…", "pay-per-view", inclusive conteúdo adulto gerado por IA). KYC
  e verificação de idade **não** destravam. Criar a conta é fácil; o banimento vem
  na revisão de underwriting, com risco de **saldo congelado por meses**. A premissa
  do estudo paralelo ("Stripe aceita adulto explícito") estava **errada**.
- **Processadoras adultas internacionais (CCBill, Segpay, Epoch, Verotel) não
  servem:** não oferecem PIX, cobram em USD/EUR e **não aceitam CNPJ brasileiro**
  (exigiriam entidade estrangeira). dLocal proíbe pornografia; EBANX é só
  cross-border. O trilho vencedor do mercado BR é **doméstico, BRL/PIX**.

## Decisão (provisória) — trilho doméstico com failover

1. **Transfeera — candidato principal.**
   - **Proof point forte:** é o PSP que processa o PIX do **líder de mercado** do
     segmento adulto no Brasil (confirmado em 08/10/2026 decodificando o QR dinâmico
     do checkout do líder: campo 26 → `qrcode.transfeera.com/cob/…`; recebedor
     "QMS Internacional"). Ou seja, **tolera conteúdo adulto na prática**, não só
     "silêncio nos termos".
   - Instituição de pagamento autorizada pelo Bacen; **PIX-in** (cobrança/`cob` via
     API) **e PIX-out** (transferência em massa via API) são o **negócio central**
     dela → cobre compra de token (membro) **e** saque das performers numa casa só.
   - ⚠️ **Ressalva de risco operacional:** em **jul/2025 o Bacen suspendeu
     cautelarmente** o PIX da Transfeera (e de outras 2 instituições) após o ataque
     à **C&M Software** (provedor de conexão ao PIX; ~R$ 400 mi desviados) — **não
     foi falha/fraude da Transfeera**, foi ataque na cadeia de fornecimento. O QR do
     líder estar **no ar em out/2026** prova que voltou a operar. Lição: manter
     **failover**.

2. **Efí (ex-Gerencianet) ou Woovi/OpenPix — failover.**
   - Termos lidos **não vetam** conteúdo adulto (só regras genéricas de material
     "obsceno"); API PIX completa (QR, pix-out, webhooks, mTLS).
   - Efí: PIX-in ~1,19% via API, **PIX-out grátis**. Woovi: PIX-in ~R$ 0,85 ou
     0,80%, PIX-out ~R$ 1,00. "Sem veto" ≠ aceite formal — exigir confirmação escrita.

3. **Evitar o cluster "bets"** (SuitPay, Ezzebank, PrimePag, Pay2m): risco de
   contraparte severo (saldos de lojista presos, cerco regulatório 2024–2026).
   **Mercado Pago e PagBank/PagSeguro vetam adulto** por escrito.

4. **PicPay:** a ser perguntado, **não confirmado**. Aparecer como botão no checkout
   do líder provavelmente é carteira roteada pelo PSP, não adquirente direto do
   vertical; resolve menos (método, não PIX-in+out completo).

## Atualização 08/10/2026 (2ª rodada de pesquisa — fecha lacunas)

- **Status regulatório confirmado dos 3 recomendados** (reduz risco de contraparte):
  - **Transfeera** — IP autorizada pelo Bacen desde nov/2023.
  - **Efí (ex-Gerencianet)** — IP autorizada pelo Bacen.
  - **Woovi** — a própria página se declara "Instituição de Pagamento regulada pelo
    Banco Central" (razão social Woovi Instituição de Pagamento LTDA, CNPJ
    54.811.417/0001-63, conexão direta ao Bacen). Isso **melhora** o Woovi: antes
    tratado como "camada de gateway com risco downstream"; agora figura como IP
    própria. (A confirmar na lista oficial crua do Bacen — ainda não lida.)
- **Zoop — DESCARTADA por falta de evidência + sinal de ecossistema contrário:** os
  termos públicos da Zoop não trazem lista de ramos proibidos (ficam em "regras
  operacionais" fora do ar), então a alegação de terceiros "Zoop aceita MCC 5967"
  **segue sem qualquer evidência**. E um subadquirente que roda **sobre a Zoop**
  (Cobre Fácil) **proíbe** "conteúdo pornográfico, libidinoso" e "prostituição" —
  o ecossistema tende a barrar adulto. **Não contar com a Zoop.**
- **Ainda em aberto:** lista oficial crua de IPs do Bacen; termos de **Iugu**
  (bloqueio de proveniência na leitura) e **Pagar.me** (não vieram limpos) — mas
  nenhuma das duas é promissora o bastante para mudar o plano (Pagar.me é da Stone,
  perfil conservador; Iugu não advertisa adulto).
- **Conclusão inalterada e reforçada:** Transfeera (principal) + Efí/Woovi (failover),
  os três confirmados como instituições de pagamento reguladas. A prova final para
  todos continua sendo o **"sim" por escrito** do onboarding.

## Riscos estruturais a validar com o jurídico

- **Token resgatável = possível moeda eletrônica.** As Resoluções BCB 494–498/2025
  endureceram a autorização de instituições de pagamento. A blindagem é estruturar o
  token como **crédito pré-pago de uso na plataforma, NÃO resgatável em dinheiro**
  (o que a economia da Limen **já faz**: membro compra e gasta token; quem saca é a
  performer, como repasse de marketplace). Validar com o advogado.
- **Modelo de repasse (marketplace split)** em que a Limen idealmente **não retém
  saldo de usuário**, para não precisar de autorização própria de IP no lançamento.
- Prazo regulatório: fechar PSP **bem antes de maio/2026** (janela das novas regras
  de IP do Bacen).
- Faturamento/descritor: usar razão social **neutra** na fatura/PIX (recomendação do
  parecer jurídico Monteseiler, 08/10/2026), não "Limen Conteúdo Adulto".

## Engenharia — como vamos implementar

- **Não** fazer troca Asaas→X direta. Extrair uma **`PaymentGatewayInterface`
  agnóstica** sobre a arquitetura `AsaasClientInterface` + Fake que já existe, com
  **Transfeera como adapter principal e Efí/Woovi como failover**. Assim a próxima
  troca forçada (se vier) custa dias, não semanas. Webhook idempotente por id de
  evento e ledger append-only **permanecem invariantes** (ver CLAUDE.md).
- **O go-live é o único bloqueado por esta decisão.** O resto do produto e a suíte
  de testes **não dependem** dela: a lógica de economia (ledger, splits 80/20·70/30,
  payout com floor) é **agnóstica de gateway** e já foi validada (UAT Fase 5/6)
  contra o sandbox do Asaas — essa validação **vale** porque a Transfeera pluga na
  mesma interface. Dá para seguir testando/evoluindo tudo em paralelo.

## Próximos passos (de PO, antes de qualquer código de integração)

1. Disparar contato **comercial** (não suporte) a **Transfeera, Efí, Woovi e PicPay**
   perguntando: aceite do MCC 5967 com CNPJ BR, PIX-in **e** PIX-out via API, taxas,
   reserva e prazo de onboarding. Guardar respostas por escrito. *(feito em 08/10 —
   Transfeera/Efí/Woovi por email; PicPay via WhatsApp.)*
2. Cruzar o aceite escrito com a autorização no Bacen (lista oficial de IPs).
3. Confirmar com o jurídico a estrutura "token não-resgatável / repasse de
   marketplace" (fecha o risco de moeda eletrônica). *(briefing enviado ao advogado
   em 08/10.)*
4. Só então: desenhar a `PaymentGatewayInterface` e a migração.

## Ligação com o parecer jurídico (Monteseiler, 08/10/2026)

Este documento responde ao **item 4 do Plano de Ação** do parecer ("mapear
adquirentes/subadquirentes de PIX que aceitem a MCC de Entretenimento Adulto").
Os demais itens (ToS duplo, LGPD/segregação, vesting + cessão de PI do CTO,
estrutura HoldCo×OpCo, INPI) seguem no acompanhamento jurídico — ver
`docs/PENDENCIAS_JURIDICAS.md` e `docs/LEGAL_GAP_ANALYSIS.md`.
