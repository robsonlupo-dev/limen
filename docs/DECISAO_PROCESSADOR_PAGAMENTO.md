# Decisão — Processador de pagamento (substituto do Asaas)

> **Status:** em aberto, mas com **avanço grande em 09/10/2026** — contato comercial
> ao vivo com Efí, Woovi e Asaas (ver "Atualização 09/10/2026"). Documento vivo.
> Nada aqui ainda está contratado; a prova final continua sendo o **"sim" por escrito**
> de cada onboarding. Fontes: relatório "Processadores PIX para plataforma adulta"
> (08/10/2026) + atendimentos comerciais de 09/10/2026.

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

## Atualização 09/10/2026 (rodada ao vivo de onboarding — respostas comerciais)

Contato comercial direto com os PSPs (chat/WhatsApp/telefone). **Regra seguida em todos:
transparência total sobre ser conteúdo adulto** — esconder o ramo para um PSP é o caminho
para saldo congelado depois. Resultado: dois "sim" fortes.

### Quadro dos processadores

| PSP | Aceita o ramo? | PIX-in | PIX-out (payout) | Cartão | Setup/mensalidade | Situação |
|---|---|---|---|---|---|---|
| **Efí** (ex-Gerencianet) | **SIM** — "não há restrição de atividade para esse CNPJ"; protocolo **22098787** | 1,19% (30 PIX grátis/mês) | **grátis** | 3,49% à vista; 3,99% (2–6x); 4,39% (7–12x) | **zero** | **Confirmado** (falta e-mail de registro) |
| **Woovi/OpenPix** | **SIM** — "a gente consegue te atender"; IP Bacen código **694**, banco liquidante, nota A no IQS | 0,80% (mín. R$0,50, **teto R$5,00**) ou R$0,85 fixo | cobrado no saque (**repassável/rentabilizável** à criadora); nº exato a confirmar | não (PIX-nativo) | **zero** (sem setup/mensalidade; o BAAS de R$20k **não** se aplica) | **Confirmado (verbal)** — falta só a tabela numérica + aceite por escrito |
| **Transfeera** | provável (é o PSP do líder do segmento) | cob via API | transferência em massa via API | — | a confirmar | **Aguardando** resposta comercial (Raquel) |
| **Asaas** | **NÃO** — recusou o ramo por política comercial | — | — | — | — | **Fora** (respondido por honestidade/registro) |

### Efí — detalhe
- Atendente confirmou por chat: **sem restrição de atividade** para o CNPJ do ramo
  (protocolo **22098787**), faz **PIX de entrada e de saída** e **cartão**. Sem setup.
- Ressalva deles (normal p/ o segmento): abertura passa por **validação documental** e
  há **monitoramento periódico** (podem pedir novas comprovações a qualquer momento).
- Cadastro de negócios exige **CNPJ** (ainda em abertura) → cadastro deixado como rascunho.
- Vantagem estrutural: **PIX-out grátis** (metade do sistema é pagar as criadoras) + cartão
  disponível para um futuro trilho tipo Privacy. Simplicidade.

### Woovi/OpenPix — detalhe (e por que importa para o jurídico)
- IP regulada pelo Bacen (código **694**), **banco liquidante próprio**, participante
  direto do PIX, nota **A** no IQS do Bacen.
- **Modelo operacional (caixinha por criadora):** os pagamentos caem na conta da
  plataforma; para repassar, informa-se a chave PIX da criadora e a Woovi cria uma
  **subconta ("caixinha")** por criadora; o repasse sai **quando a plataforma autoriza**
  (regra de saque — diária/semanal — definida pela plataforma).
- **A "caixinha" NÃO exige KYC/cadastro da criadora** (confirmado pelo comercial em
  09/10): é **controle interno da plataforma** vinculado à chave PIX de destino; a
  criadora só pede o saque dentro do Limen e a Woovi libera para a chave dela. Como as
  criadoras são **PF**, isso remove o atrito que o BAAS (PJ-only) criaria.
- **Split é grátis** (mandar para N subcontas no ato do pagamento não custa) e pode jogar
  a parcela da criadora direto na caixinha dela.
- **Sem setup, sem mensalidade, sem amarra contratual** (sem multa/*make-whole* — pode
  encerrar quando quiser). **Cobrança só sobre transação liquidada** (emitiu 10 mil QR,
  pagaram mil → paga só pelos mil).
- ⚠️ **Relevância jurídica (ressalva ii do Monteseiler):** a caixinha permite **segregar
  a parcela da criadora** (earmark) em vez de "receber 100% e repassar em bloco" — ataca
  diretamente o risco de caracterização por fluxo financeiro. Ver abaixo a nota de split.
- **Correção de proof point:** o comercial **esclareceu que Privacy e Fatal Fans NÃO são
  clientes da Woovi** — foram citados só como *exemplo do modelo*. Ou seja, a Woovi **não**
  tem a prova social de "já roda o líder do segmento"; quem tem esse proof point (decodificado)
  é a **Transfeera**. A Woovi aceita o ramo ("a gente consegue te atender"), mas isso é
  aceite comercial, não histórico com o líder.
- **Pendências a confirmar por escrito:** (1) aceite formal do ramo; (2) **tabela numérica
  completa** — principalmente o **valor do PIX-out/saque** (o PIX-in já está na tabela).
  (Os pontos antigos — KYC da caixinha e setup de R$20k — foram **resolvidos** acima.)
- Monetização do saque: a Woovi cobra pelo saque, mas a plataforma pode repassar mais à
  criadora e embolsar a diferença — payout pode virar **fonte de receita** (modelo comum
  no segmento).

### Nota de arquitetura — split na origem × carteira pré-paga (alinhado com o jurídico)
- **Split na origem NÃO se aplica à compra de token** (crédito pré-pago): no ato da compra
  **ainda não existe criadora de destino** — o membro gasta o saldo depois, pulverizado.
  Inerente a qualquer modelo de carteira (tipo saldo de app/jogo).
- **Onde o split na origem SE aplica:** compras **diretas** (PPV na DM, encomenda sob
  medida), em que a criadora é conhecida no ato. Woovi cobre bem esse caso.
- **Mitigação para o token genérico (validada pelo Monteseiler, parecer Parte 3 —
  09/10/2026):** o dinheiro do token é **Passivo Circulante** (não receita); a receita da
  Limen (base ISS/IRPJ) nasce **só no consumo, sobre o split**; a parcela da criadora é
  **repasse** (não tributável para a Limen). Isso resolve **bitributação** e o
  enquadramento penal (**"HIPÓTESE VALIDADA — BLINDAGEM EFICAZ"**), **sem** exigir split
  na origem para o token. O MCC 5967 foi esclarecido: **só existe no trilho de cartão**;
  no PIX o equivalente é o aceite do ramo — já obtido na Efí (protocolo = "Safe Harbor
  documental", nas palavras do parecer).
- **Ação de produto que caiu do jurídico:** o **ToS do Membro** deve ter cláusula de
  natureza jurídica do token: *"Créditos de Uso não resgatáveis, sujeitos a prescrição
  quinquenal, sem equivalência com moeda corrente"* (blinda contra tese de "IP não
  autorizada pelo Bacen"). Entra na F2 (documentos) do jurídico.

### Leitura atual do trilho (substitui provisoriamente o "Transfeera principal" de 08/10)
- **Dois finalistas, pau a pau: Efí e Woovi.**
  - **Efí** ganha em **simplicidade** e **PIX-out grátis**, e tem **cartão** para o futuro.
  - **Woovi** ganha em **custo de PIX-in** (teto R$5) e no **modelo de caixinha/split**
    (sem KYC da criadora, sem setup, sem amarra contratual), que agrada ao jurídico.
- **Transfeera** segue como opção/failover, pendente de resposta comercial.
- **Decisão final** espera: (a) tabela de taxas por escrito da Woovi (falta só o
  **valor do PIX-out/saque**); (b) resposta da Transfeera. Como entrada e saída são
  **trilhos independentes**, há inclusive a opção de **receber por um e pagar por outro**
  (ex.: Woovi na entrada barata, Efí na saída grátis) — só vale se o ganho compensar a
  complexidade de dois provedores.
- ⚠️ **Gate prático — todos dependem do CNPJ:** os três (Efí, Woovi, Transfeera) exigem
  **CNPJ para FINALIZAR o cadastro**, e o CNPJ ainda está em abertura (F3 do jurídico).
  A sequência certa é: (1) pergunta de elegibilidade do ramo **agora** (feito/em curso),
  (2) abertura do CNPJ com o CNAE certo (jurídico), (3) finalização do cadastro no PSP
  escolhido **por último**. Nenhum onboarding fecha antes do CNPJ — não é bloqueio de
  PSP, é a ordem natural.

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
estrutura societária + Contrato de Mútuo do capital, INPI) seguem no acompanhamento
jurídico — ver `docs/PENDENCIAS_JURIDICAS.md` e `docs/LEGAL_GAP_ANALYSIS.md`.

**Parecer Parte 3 (09/10/2026) — pontos que tocam esta decisão:**
- **Arquitetura financeira VALIDADA ("blindagem eficaz"):** token = Passivo Circulante,
  receita só sobre o split no consumo → sem bitributação e sem exploração (Art. 228 CP).
  Não exige split na origem para o token (ver "Nota de arquitetura" acima).
- **Protocolo escrito da Efí** aceitando o ramo para PIX = **Safe Harbor documental**
  (prova de boa-fé perante adquirentes/Bacen). Guardar o protocolo 22098787 e o e-mail.
- **ToS do Membro** precisa da cláusula de natureza jurídica do token (crédito de uso
  não resgatável, prescrição quinquenal, sem equivalência com moeda) — entra na **F2**.
- **Honorários (pacote):** R$ 20.825 (F1 penal+PSP, F2 documentos, F3 societário, F4 marca),
  50% no aceite / 50% na entrega de F2 e F3 — **decisão de PO, fora do escopo deste ADR**.
