# Decisões de produto — agosto de 2026

Registro das decisões de produto tomadas na virada de agosto/2026 (pré-lançamento),
cada uma com **contexto**, **decisão** e **motivo**. O motivo é o mais importante: é o
que impede que alguém reverta uma decisão no futuro sem saber por que ela foi tomada.

As regras de economia consolidadas estão em `docs/ECONOMIA.md`; aqui ficam as
**decisões** que levaram ao estado atual.

---

## 1. Portão de conversa (membro → performer) — REMOVIDO

- **Contexto:** a conversa só nascia depois que a performer descobria o membro e
  demonstrava interesse. O membro não conseguia iniciar nada por conta própria.
- **Decisão:** qualquer membro com saldo pode iniciar uma conversa com qualquer
  performer, sem ela ter demonstrado interesse antes.
- **Motivo:** o portão dependia de a performer descobrir o membro primeiro. Numa
  plataforma em pré-lançamento, com pouca gente circulando, isso resultava em **zero
  conversas** — a descoberta simplesmente não acontecia. Removido o portão, o membro
  passa a mover o primeiro passo.

## 2. Cobrança do chat passou para o momento do ENVIO

- **Contexto:** para conversar, o membro precisava primeiro clicar em "desbloquear
  acesso" (um passo separado, fora da conversa) e só depois escrever.
- **Decisão:** a cobrança da abertura (2 tokens, ou 1 para Black/FC) acontece no ato
  do **envio da primeira mensagem, dentro da própria conversa**. A janela de 30 dias e
  a carência de 15 dias **continuam exatamente como eram**.
- **Motivo:** o passo separado de "desbloquear" antes de escrever era atrito
  desnecessário e confundia (parecia uma segunda cobrança). Cobrar no envio é o gesto
  natural. Nada da retenção mudou — apenas o **momento** e o **lugar** da cobrança.

## 3. Chat passou a pagar 80/20 (fim do crédito fixo de 1 token)

- **Contexto:** a abertura de conversa pagava um valor **fixo de 1 token** à
  performer, independentemente de o membro ter pago 1 ou 2.
- **Decisão:** a abertura de conversa passa a dividir **80% para a performer / 20%
  para a Limen**, como todos os outros fluxos. Custo 2 → performer recebe 1,60; custo
  1 → recebe 0,80.
- **Motivo:** sobre uma abertura de 2 tokens, o crédito fixo de 1 era **50%**, enquanto
  o contrato com a performer promete **80%**. Era a única retenção sistemática indevida
  da plataforma. A mudança fez a promessa de 80% valer também no chat.

## 4. Economia em decimal exato, com arredondamento único no saque

- **Contexto:** o valor que a performer recebia era arredondado a cada transação. Isso
  só fechava certo por coincidência dos preços "redondos"; um conteúdo de 7 ou uma
  gorjeta de 13 truncavam frações que pertenciam a ela.
- **Decisão:** os valores da performer passam a ser guardados com **precisão exata**
  (até quatro casas), sem arredondar em transação nenhuma. O **único** arredondamento
  acontece na **conversão para reais no saque**, sempre **para baixo**, e a fração que
  sobra **fica no saldo da performer** para o próximo saque.
- **Motivo:** garantir que a promessa de 80% seja verdade em **qualquer** preço, não só
  nos redondos, e que o arredondamento **nunca** vire uma retenção escondida a favor da
  plataforma. Arredondar para baixo e preservar a sobra assegura que a performer
  receba, ao longo do tempo, exatamente o que ganhou.

## 5. Preço do chat simétrico (2 tokens nos dois sentidos)

- **Contexto:** ao mover a cobrança para o envio, surgiu a opção de cobrar menos de
  quem só quer LER uma mensagem que a performer mandou.
- **Decisão:** o preço é o mesmo nos dois sentidos — 2 tokens (ou 1 para Black/FC),
  seja o membro iniciando, seja ele respondendo/lendo a primeira mensagem da performer.
- **Motivo:** um preço menor na leitura criaria o incentivo errado. O membro esperaria
  a performer escrever primeiro para pagar menos, e a performer, percebendo isso,
  pararia de escrever. O preço igual mantém os dois lados dispostos a iniciar.

## 6. Mensagens grátis diárias da performer mantidas em 15

- **Contexto:** a performer pode iniciar conversas com membros de graça até um limite
  diário; cogitou-se baixar esse limite.
- **Decisão:** o limite fica em **15 mensagens grátis por dia** por performer.
- **Motivo:** 15/dia é o limite anti-spam adequado. O freio principal contra abuso não
  é essa cota, e sim o custo que o **membro** paga para **ler** a mensagem. Não baixar
  para 5 sem nova decisão.

## 7. Retenção de conversa mantida (30 + 15 + arquivamento)

- **Contexto:** ao rever o chat, avaliou-se mexer nos prazos de retenção.
- **Decisão:** os prazos ficam **exatamente como estão** — 30 dias de acesso pleno, 15
  dias de carência, depois o arquivamento das mensagens.
- **Motivo:** a retenção curta reduz risco numa plataforma de conteúdo adulto, e não
  havia problema relatado que justificasse mexer.
- **Ponto registrado para revisitar:** quando a conversa é arquivada, **a performer
  perde o histórico junto com o membro** (as mensagens somem para os dois lados ao
  mesmo tempo). É intencional por ora, mas deve ser **reaberto se houver reclamação**
  de performers que queiram manter o histórico das próprias conversas.

## 8. Presença da performer derivada da sessão, com opt-out

- **Contexto:** havia um botão manual de "disponível para conversa" que, na prática,
  não bloqueava recebimento de mensagem — só afetava um selo — e podia ficar aceso
  esquecido.
- **Decisão:** a presença ("online agora") passa a ser **derivada da atividade real**
  da performer na plataforma, não de um botão. Quem quiser não aparecer tem um
  **opt-out** ("aparecer offline"), que a esconde do catálogo, mas **continua
  recebendo mensagens normalmente**.
- **Motivo:** presença automática é mais honesta e não fica "presa" ligada. O opt-out
  atende quem quer discrição sem cortar o canal de mensagens. **Presença de MEMBRO
  continua nunca exposta** — a mudança é só do lado da performer.

## 9. Botão de saída rápida (Panic Button): só desktop, só ícone

- **Contexto:** o botão de saída de emergência estava grande e rotulado, e no celular
  atrapalhava/confundia (era lido como "fechar").
- **Decisão:** no **celular** o botão flutuante **some** (a saída nativa — bloquear o
  aparelho — é mais rápida); no **desktop** ele vira um **ícone pequeno e discreto**,
  vermelho, no canto superior esquerdo, com o rótulo **"Panic Button" (em inglês)**
  aparecendo só ao passar o mouse. O atalho de teclado (dois toques em Esc) continua
  funcionando em qualquer tela.
- **Motivo:** menos chamativo de relance para quem passa perto (o rótulo em inglês é
  menos legível para um observador casual), sem perder a função de emergência. A lógica
  de saída em si não mudou.

## 10. Landing (página de entrada): rótulos "Associado" e "Anfitrião"

- **Contexto:** o formulário de lista de espera pedia para a pessoa escolher o papel
  (membro ou performer), e os rótulos visíveis estavam sendo calibrados.
- **Decisão:** os rótulos visíveis passam a ser **"Associado"** (para o membro) e
  **"Anfitrião"** (para a performer). O valor técnico por trás continua o mesmo.
- **Motivo:** linguagem mais alinhada ao posicionamento premium/clube da marca. É só o
  texto visível — nenhuma regra por trás muda.

## 11. Presente virtual passa a 80/20 — IMPLEMENTADO

- **Status:** decisão aprovada e **implementada** (21/08/2026, PR
  `feat/gift-split-and-tier-visibility`). A partir daqui todo presente novo divide
  80/20; **lançamentos antigos mantêm 75/25 congelado** (a taxa é gravada em cada
  transação e nunca recalculada).
- **Contexto:** o presente virtual dividia **75% performer / 25% Limen**, enquanto a
  gorjeta e o conteúdo permanente dividem **80/20**. Presente e gorjeta são
  economicamente parecidos — transferência de valor do membro para a performer sem
  serviço de infraestrutura por trás.
- **Decisão:** o presente virtual passa a dividir **80% performer / 20% Limen**, igual
  a gorjeta e conteúdo.
- **Motivo:** o presente **não tem custo de infraestrutura** (ao contrário de live e
  chamada, que consomem vídeo em tempo real). Sem esse custo, não há razão para a
  performer receber menos do que recebe numa gorjeta ou num conteúdo.
- **Regra final da economia (o princípio que passa a valer):** **80% no que NÃO custa
  infraestrutura** (conteúdo, gorjeta, presente, abertura de conversa) e **70% no que
  CUSTA** (live pública e chamada privada, que consomem vídeo em tempo real). O 70/30 de
  live e chamada permanece; só o presente sobe de 75 para 80.

## 12. Conteúdo Premium vira compra avulsa + tiles bloqueados visíveis — IMPLEMENTADO

- **Status:** aprovada e **implementada** (21/08/2026, mesmo PR).
- **Contexto:** antes, uma peça acima do tier do membro **sumia** da galeria do perfil —
  o membro nem sabia que existia. Isso não protegia a assinatura; escondia o valor
  dela. E o Premium só era acessível a partir de Prestige.
- **Decisão (duas partes):**
  1. **Todos os níveis passam a APARECER no perfil**, mesmo os que o membro não pode
     comprar — como **tile bloqueado** (espaço reservado, selo do nível e preço/tier).
     A imagem original **nunca** é servida a quem não pagou (bloqueio no servidor, não
     por filtro visual removível).
  2. **Premium vira compra avulsa:** qualquer membro compra pagando o **preço cheio em
     tokens**. **Exclusivo e FC Only continuam travados por tier** (Black+ / FC):
     aparecem bloqueados, com o caminho para assinar, mas não são compráveis avulso.
- **Como o desconto do assinante se aplica (decisão do PO nesta sessão):** o desconto
  por tier **continua só na compra de tokens** (o assinante já paga menos em reais pelos
  tokens). **Não** há desconto sobre o preço em tokens da peça no desbloqueio — o membro
  paga o preço cheio, e a performer recebe **80% do preço cheio**. O incentivo do
  assinante passa a ser o desconto (tokens mais baratos), não o bloqueio.
- **Motivo:** mostrar o conteúdo bloqueado (em vez de escondê-lo) expõe o valor da
  assinatura e do catálogo; abrir o Premium à compra avulsa aumenta a monetização sem
  tirar o que é exclusivo dos tiers altos (Exclusivo/FC Only).
- **Texto dos tiles (regra):** nomear sempre o **TIER de assinatura**, nunca o nível de
  conteúdo. "Disponível no Black", "Disponível no Círculo de Fundadores" — jamais
  "Assinantes Exclusivo" (Exclusivo é nível de conteúdo, não um tier).

## 13. Seletor de qualidade para o MEMBRO (espectador) — NÃO nesta etapa

- **Status:** decidida (22/08/2026, PR `feat/live-broadcast-controls`). **Não implementada
  de propósito.**
- **Contexto:** a performer passou a escolher a resolução que ENVIA (1080p/720p/480p, §
  "Controles de transmissão da performer"). Surgiu a pergunta de dar ao espectador o mesmo
  seletor.
- **Decisão:** **o membro NÃO ganha seletor de qualidade agora.** O ajuste automático do
  player conforme a conexão do espectador continua valendo (é do transporte, não custa
  nada à parte).
- **Motivo:** oferecer VÁRIAS resoluções ao espectador exige **transcodificação em tempo
  real** (gerar 1080/720/480 do mesmo stream), que é **custo por minuto de transmissão** e
  **comprime a margem do fluxo de live**. Além disso, **o membro nunca recebe melhor do que
  a performer envia** — oferecer "1080p/4K" quando ela transmite em 720p seria uma opção
  falsa. Enquanto a live é a economia atual (live 70/30, § 11), o custo de transcodificação
  não se justifica.
- **Quando revisitar:** se/quando houver orçamento de infraestrutura para transcodificação
  (SFU com camadas/simulcast já cobre parte disso sem transcodificar — avaliar simulcast
  antes de transcodificação plena).

## 14. Legenda automática (transcrição de áudio da live) — BACKLOG, gate jurídico

- **Status:** **backlog futuro.** Não implementada, e **não implementar sem passar pelo
  jurídico primeiro.**
- **Contexto:** legenda automática ao vivo melhora acessibilidade, mas o áudio da performer
  precisaria ser transcrito.
- **Ressalva (o motivo de não ser trivial):** transcrição ao vivo exige **serviço de
  terceiro cobrado por minuto** E **enviaria o áudio das performers para FORA da
  plataforma** (o subprocessador de transcrição). Isso é **decisão de privacidade** — o
  áudio é conteúdo íntimo de uma trabalhadora verificada — que tem de ser **avaliada com o
  jurídico** (base legal, DPA, registro de subprocessador, aviso à performer) **antes de
  qualquer implementação**. Mesma disciplina do captcha/KYC (subprocessador que vê dado
  sensível entra na política + DPA antes de ligar).

## 15. Chamada privada a partir da live: a live PAUSA, não encerra — IMPLEMENTADO

- **Status:** aprovada e **implementada** (28/08/2026, PR `feat/private-call-from-live`).
  (§13/§14 acima são do PR `feat/live-broadcast-controls`/#208, já mergeado — por isso esta
  sequência começa em §15.)
- **Contexto:** durante uma live pública, um membro pode pedir chamada privada 1:1. Ao
  aceitar, era preciso decidir o que fazer com a transmissão pública em andamento.
- **Decisão:** a live **PAUSA**, não encerra. Quem está assistindo **continua conectado**
  (sem tela preta, sem drop) e vê "Em chamada privada — volta já"; o **chat da sala segue
  funcionando**; ao fim da chamada a live **RETOMA sozinha**, sem recarregar. Se a
  performer encerrar tudo durante a chamada, a live termina com aviso.
- **Motivo:** encerrar a live a cada chamada privada destruiria a audiência acumulada e o
  momento da transmissão — o público teria de ser reconquistado do zero a cada 1:1. Pausar
  preserva a sala, o chat e os espectadores, e a chamada privada vira um "intervalo", não
  um fim. É retenção de audiência.
- **Como (engenharia):** a pausa é um **sub-estado** (`live_sessions.paused_at`), não um
  novo `status` — a sessão segue `status='live'`, então os viewers não caem em 410. O A/V
  da chamada 1:1 roda numa **sala LiveKit SEPARADA** (privacidade travada por teste: o
  token do viewer da live só concede a sala da live, view-only — nada da chamada vaza). O
  relógio da cobrança começa **só quando a performer aceita E a chamada conecta**; a espera
  entre pedido e resposta nunca é cobrada; pedido sem resposta expira sem custo. A economia
  REUSA CallService/MinuteBiller (70/30, minuto inteiro pré-pago, R1–R4) — nada reescrito.

## 16. Saldo insuficiente no meio da chamada: comprar SEM cair, e o que acontece se não der — IMPLEMENTADO

- **Status:** aprovada e **implementada** (28/08/2026, mesmo PR).
- **Aviso é SÓ do MEMBRO.** A performer NUNCA recebe indicação de que o saldo dele está
  acabando — é constrangedor e mudaria o comportamento dela sem necessidade (M.13.10). O
  aviso aparece quando o saldo não cobre mais o próximo minuto (~30s+ de margem, dentro do
  minuto corrente), com botão de comprar.
- **Comprar SOBRE a chamada, sem derrubar.** A compra acontece num painel POR CIMA do vídeo
  (não outra aba/janela — pop-up e troca de aba no celular derrubam a conexão). O relógio
  **continua correndo** durante o pagamento. Reusa o fluxo existente: `wallet.purchase` gera
  o PIX, `wallet.pending` pola status+saldo até o **webhook idempotente** creditar (nada de
  crédito inventado no cliente). Ao compensar, o saldo novo **vale na hora** e o aviso some,
  sem recarregar; o próximo minuto passa a ser cobrado normalmente.
- **Se não comprar a tempo e o saldo zerar:** a chamada encerra **limpo** — nunca saldo
  negativo, nunca cobra minuto não prestado; a live retoma. **Decisão do PO sobre o PIX em
  trânsito:** como o relógio corre durante o pagamento, ele pode zerar o saldo ANTES de o
  PIX compensar. Nesse caso NÃO se inventa crédito nem fiado: a chamada encerra, e quando o
  PIX compensar **depois**, os tokens entram na carteira normalmente (não se perdem). A
  mensagem de encerramento deixa isso claro ("seu pagamento em andamento será creditado
  quando compensar — nada se perde"), e com saldo ele pode **pedir a chamada de novo** (não
  se tenta "retomar" a chamada anterior automaticamente).

## 17. Apelido do membro no lugar do "Fã #NNNN" — IMPLEMENTADO

- **Status:** aprovada e **implementada** (17/09/2026, PR `feat/member-nickname`).
- **Decisão:** o membro pode ESCOLHER um apelido, e é assim que a performer passa a
  chamá-lo, no lugar do `Fã #NNNN`. Quem não escolher **continua como está hoje** (o
  FanAlias). É opcional em qualquer etapa (cadastro e perfil), nunca bloqueia cadastro
  nem funcionalidade.
- **Motivo:** a foto do membro já é visível à performer (decisão do PO, fix/member-photo).
  Um identificador NUMÉRICO ao lado de um rosto não protege nada e impede a relação.
  Apelido cria personagem, e personagem cria vínculo — que é o que sustenta gorjeta,
  chamada e assinatura. É a MESMA lógica da foto: onde o rosto já quebrou o anonimato, o
  número só atrapalha.
- **Onde ENTRA (exibição):** catálogo de membros da performer, lista e cabeçalho de
  conversa, chat da live, feed de gorjetas/presentes na live, painel de visitantes e
  "Últimas gorjetas". Dona única do "como exibir": `App\Support\MemberDisplayName`.
- **Onde NÃO entra (não negociável):** o FanAlias CONTINUA sendo o identificador técnico
  **gravado** no ledger (`token_ledger.description`), no extrato de ganhos
  (`PerformerEarningsService`), nos logs e em toda auditoria. O apelido é camada de
  APRESENTAÇÃO; nada no registro financeiro depende dele. `user_id`, nome real e e-mail
  seguem nunca expostos. **Refinamento posterior (decisão nº 18):** o extrato de ganhos
  e as "Últimas gorjetas" passaram a EXIBIR o apelido AO LADO do FanAlias (junção na
  leitura), mas o que fica GRAVADO segue sendo só o FanAlias — o invariante acima vale.
- **Regras do campo:** 3–20 caracteres; ÚNICO (case/acento-insensível), com erro genérico
  ("esse apelido não está disponível") quando já existe — nunca confirma que há um membro
  com ele; trocável no máximo **uma vez a cada 7 dias** (a performer constrói relação com
  o nome; troca semanal quebra o vínculo e serve para fugir de bloqueio).
- **Validação MAIS RÍGIDA que a do chat (deliberado):** o filtro de chat NÃO barra troca
  de contato (decisão registrada, § do chat); o apelido é campo PÚBLICO e PERMANENTE, então
  tem validação própria (`MemberNicknameService`) que barra telefone (5+ dígitos
  consecutivos), e-mail/@, URL/domínio, rede social (com anti-desvio leet/repetição
  reusando `ChatContentFilter::normalizeForMatch`), palavra reservada (limen/suporte/admin/
  moderador/oficial…), nome de performer existente (anti-personificação, comparação
  normalizada) e conduta abusiva. Ver `docs/PENDENCIAS_JURIDICAS.md`.
- **Aviso no momento da escolha:** a tela deixa claro, ANTES de salvar, que o apelido é
  PÚBLICO — visível para as performers E para os outros membros no chat de uma live. Não é
  bilhete privado.
- **Moderação (neste PR):** o moderador pode FORÇAR a remoção do apelido (painel de
  moderação, por string do apelido — pública e única, não expõe id); o membro volta ao
  FanAlias até escolher outro, e o cooldown de troca é preservado. A **denúncia pública** do
  apelido (entrada do denunciante) ficou para um **PR dedicado** — o pipeline de denúncia
  usa handle numérico e o membro é identificado por FanAlias em hex, então encaixá-la ali
  exige revisão anti-oráculo própria (registrado no handoff).

## 18. Apelido no extrato de ganhos e nas "Últimas gorjetas" — AO LADO do FanAlias

- **Status:** aprovada e implementada (17/09/2026, PR `feat/nickname-in-earnings`).
  Complementa a decisão nº 17.
- **Contexto:** a decisão nº 17 trouxe o apelido para as telas de EXIBIÇÃO, mas manteve
  o extrato de ganhos (`PerformerEarningsService`) SÓ no FanAlias — o extrato é a trilha
  de conferência financeira, e ali o apelido não podia entrar sozinho.
- **Decisão:** o extrato de ganhos e o painel "Últimas gorjetas" passam a exibir o
  apelido **AO LADO** do FanAlias, o alias em menor destaque — ex.: "Comandante · Fã
  #6393". Sem apelido, **só o alias, como hoje**. É exibição dos DOIS juntos, nunca um no
  lugar do outro (diferente das outras 6 telas, onde o apelido substitui o alias).
- **INVARIANTE (não negociável):** o lançamento continua **gravado com o FanAlias**
  (`token_ledger.description`); o apelido entra por **junção na LEITURA** (resolve o
  member_id pelo elo reverso de cada fonte, junta `users.nickname`), **nunca é persistido**
  no ledger nem em nenhum registro financeiro.
- **Motivo:** o apelido é MUTÁVEL (trocável a cada 7 dias). Se a trilha financeira
  passasse a depender dele, um lançamento antigo mudaria de nome sozinho e a performer
  perderia a capacidade de reconciliar. Com os dois lado a lado, ela ganha o nome que usa
  no dia a dia E o identificador estável para conferência.

## 19. Validação do apelido — critério de abuso mais rígido que o do chat (confirmações)

- **Status:** decisão do PO registrada junto ao PR `feat/nickname-in-earnings`
  (discussão de 17/09/2026). Confirma e explicita o comportamento já implementado em
  `MemberNicknameService`.
- **MANTÉM bloqueado no apelido — personificação:** personificação da PLATAFORMA
  (suporte/admin/moderador/oficial) e de PERFORMER existente. **Motivo:** é vetor de
  golpe usando a marca, independente da política de contato.
- **MANTÉM bloqueado no apelido — contato:** telefone, e-mail e rede social.
  **Motivo:** o apelido é campo **público e permanente**, exibido a estranhos em toda
  live — diferente de uma mensagem 1:1 — e expõe o próprio membro. Revisitar só depois do
  parecer jurídico sobre a política de contato no chat (ver `docs/PENDENCIAS_JURIDICAS.md`).
- **CONFIRMADO sobre conduta:** palavrão genérico **NÃO** é bloqueado (linguagem
  explícita entre adultos é esperada na plataforma); o que bloqueia é **insulto
  DIRECIONADO e ameaça**, com o desarme por **qualificador consensual** — comportamento
  atual do filtro, mantido de propósito. No apelido o critério de abuso é **mais rígido**
  que no chat, porque é nome público e permanente.
