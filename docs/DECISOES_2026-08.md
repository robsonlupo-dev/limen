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
