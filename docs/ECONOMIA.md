# Economia da Limen — regras de negócio

**Fonte canônica das regras de dinheiro da plataforma.** Escrito para ser lido por
qualquer pessoa — sócio, advogado, contador — sem conhecimento técnico. Descreve
**quem paga, quanto, quando, e quem recebe**.

As decisões aqui foram fechadas pelo dono do produto (Robson) entre agosto de 2026
e a economia decimal de 19/08/2026, mais os ajustes de pré-lançamento registrados em
`docs/DECISOES_2026-08.md`. Quando este documento e qualquer outro divergirem sobre
economia, **este vence**.

> Cada regra vem com o **porquê** entre parênteses ou em nota. O porquê não é enfeite:
> é o que impede alguém de reverter uma decisão sem saber o motivo.

---

## 1. A moeda: tokens

- **Existe uma moeda única: o token.** Tudo — abrir conversa, desbloquear conteúdo,
  assistir live, fazer chamada, dar gorjeta, mandar presente — passa por tokens.
- **Tokens nunca expiram.** O que o membro comprou ou ganhou fica com ele.
- **O membro sempre gasta tokens inteiros** (compra e gasta em números redondos). Só
  o valor que a **performer recebe** pode ter casas decimais (ver seção 9).
- **A performer vê sempre o valor em reais ao lado do valor em tokens**, para saber
  quanto aquilo representa no saque.

---

## 2. Como o membro obtém tokens

### 2.1 Comprando pacotes (via PIX)

Preço cheio (sem desconto de assinatura):

| Pacote  | Preço      | Tokens | Preço por token |
|---------|------------|--------|-----------------|
| Starter | R$ 49,90   | 50     | R$ 1,00         |
| Popular | R$ 99,90   | 105    | R$ 0,95         |
| Premium | R$ 199,90  | 220    | R$ 0,91         |
| VIP     | R$ 499,90  | 580    | R$ 0,86         |

> **Âncora inviolável:** o pacote Starter custa exatamente **R$ 1,00 por token**. É a
> referência de preço da plataforma e não pode ser rebaixada.

### 2.2 Ganhando na assinatura (tokens inclusos)

Quem assina um Círculo recebe uma **franquia mensal** de tokens, creditada no
primeiro dia de cada ciclo. Esses tokens não expiram e entram no saldo normal (ver
seção 3).

---

## 3. Assinaturas (os Círculos)

São cinco níveis. A assinatura em si é **100% da Limen** — não há divisão com
performer nenhuma. Cada nível dá: uma franquia mensal de tokens, um desconto na
compra avulsa de pacotes, e acesso a certos tipos de conteúdo.

| Círculo    | Mensalidade   | Tokens inclusos/mês | Desconto na compra | Vagas |
|------------|---------------|---------------------|--------------------|-------|
| Explorador | R$ 89,90      | 105                 | 10%                | ∞     |
| Insider    | R$ 189,90     | 230                 | 10%                | ∞     |
| Prestige   | R$ 389,90     | 490                 | 15%                | ∞     |
| Black      | R$ 749,90     | 1.000               | 20%                | 500   |
| FC (Founders Circle) | R$ 1.490,00 | 2.100     | 25%                | 100   |

**Regra de proteção de margem:** nenhuma combinação de pacote + desconto pode deixar
o custo efetivo do token abaixo de **R$ 0,625**. Abaixo disso a margem bruta cairia
de 25%, o piso mínimo aceitável (ver seção 11).

> **História:** os descontos já foram maiores (chegaram a 40% no FC). Foram reduzidos
> ao fechar a economia, justamente para nunca furar o piso de R$ 0,625/token.

### 3.1 O que cada Círculo dá de acesso

A regra geral: **o Círculo dá a CHAVE de acesso; o conteúdo continua sendo pago com
tokens** (o preço é definido pela performer). "Grátis" = acesso sem custo. "Paga
tokens" = tem acesso, mas paga para desbloquear. "Sem acesso" = não vê aquele nível.

| Benefício                    | Não-assinante | Explorador | Insider | Prestige | Black | FC |
|------------------------------|---------------|------------|---------|----------|-------|----|
| Abrir conversa (custo)       | 2 tk          | 2 tk       | 2 tk    | 2 tk     | 1 tk  | 1 tk |
| Conteúdo Aberto              | Paga tokens   | Grátis     | Grátis  | Grátis   | Grátis | Grátis |
| Conteúdo Premium             | Paga tokens   | Paga tokens | Paga tokens | Paga tokens | Paga tokens | Paga tokens |
| Conteúdo Exclusivo           | Sem acesso    | Sem acesso | Sem acesso | Sem acesso | Paga tokens | Paga tokens |
| Conteúdo FC Only             | Sem acesso    | Sem acesso | Sem acesso | Sem acesso | Sem acesso | Paga tokens |
| Live pública                 | Paga tokens   | Paga tokens| Paga tokens| Paga tokens| Paga tokens| Paga tokens |
| Chamada privada              | Paga tokens   | Paga tokens| Paga tokens| Paga tokens| Paga tokens| Paga tokens |
| Gorjeta                      | Paga tokens   | Paga tokens| Paga tokens| Paga tokens| Paga tokens| Paga tokens |
| Presente                     | Paga tokens   | Paga tokens| Paga tokens| Paga tokens| Paga tokens| Paga tokens |
| Confirmação de leitura       | Não           | Sim        | Sim     | Sim      | Sim   | Sim |
| Tokens inclusos/mês          | —             | 105        | 230     | 490      | 1.000 | 2.100 |
| Desconto na compra           | —             | 10%        | 10%     | 15%      | 20%   | 25% |
| Modo invisível / discreto    | Não           | Não        | Não     | Não      | Sim   | Sim |
| Número FC permanente         | Não           | Não        | Não     | Não      | Não   | Sim |

> **A única situação em que a performer sabe que um membro é assinante — e de qual
> nível — é quando ele desbloqueia um conteúdo "FC Only"** (aí ela sabe que é FC,
> porque só FC alcança esse nível). Fora disso, **o nível do membro é invisível para
> a performer** (ver seção 12). *Mostrar depois é fácil; esconder depois é impossível.*

---

## 4. Quem recebe o quê (a divisão por tipo de transação)

Quando o membro gasta tokens, a Limen retém uma parte e credita o resto à performer.
A divisão **depende do TIPO de transação, nunca do lugar onde ela acontece** (uma
gorjeta é 80/20 seja na live, no chat ou no perfil).

| Tipo de transação            | Performer recebe | Limen retém |
|------------------------------|------------------|-------------|
| Conteúdo permanente          | 80%              | 20%         |
| Gorjeta                      | 80%              | 20%         |
| Abertura de conversa (chat)  | 80%              | 20%         |
| Presente virtual             | 80%              | 20%         |
| Live pública (por bloco)     | 70%              | 30%         |
| Chamada privada (por minuto) | 70%              | 30%         |
| Destaque no catálogo (boost) | 0% (100% Limen)  | 100%        |
| Interesse revelado           | 0% (100% Limen)  | 100%        |
| Assinatura de Círculo        | 0% (100% Limen)  | 100%        |

> **Princípio final da economia (fechado em 21/08/2026):** **80% no que NÃO custa
> infraestrutura** (conteúdo, gorjeta, presente, abertura de conversa) e **70% no que
> CUSTA** (live e chamada, que consomem vídeo em tempo real). O presente subiu de 75
> para 80 justamente por não ter custo de infra — não havia razão para pagar menos que
> gorjeta e conteúdo. **Lançamentos antigos de presente mantêm a taxa antiga (75%)
> congelada** — a taxa é gravada em cada transação e nunca recalculada.

> **Nenhuma transação cria nem destrói token.** Em toda divisão, o que sai do membro
> = o que entra para a performer + o que fica com a Limen. Sempre fecha em zero.

---

## 5. Conversa (chat)

### 5.1 Quem pode começar

**Qualquer membro com saldo pode iniciar uma conversa com qualquer performer** — ela
não precisa ter demonstrado interesse antes.

> **Por que mudou (agosto/2026):** antes, a conversa só nascia depois que a performer
> descobria o membro e demonstrava interesse. Em uma plataforma em pré-lançamento,
> com pouca gente, isso significava **zero conversas**. O portão foi removido. Detalhe
> em `docs/DECISOES_2026-08.md`.

### 5.2 Quanto custa e quando cobra

- **A abertura da conversa custa 2 tokens** para não-assinantes e para Explorador,
  Insider e Prestige; **1 token** para Black e FC.
- **A cobrança acontece no momento do ENVIO da primeira mensagem, dentro da própria
  conversa** — não há mais um passo separado de "desbloquear acesso" antes de escrever.
- Uma vez aberta, a conversa dá **mensagens ilimitadas por 30 dias**. Dentro desses
  30 dias, nada mais é cobrado.
- **O preço é simétrico: 2 tokens nos dois sentidos.** Se a performer manda a primeira
  mensagem, o membro paga os mesmos 2 tokens para ler/responder.

> **Por que simétrico:** um preço menor no lado da leitura criaria o incentivo errado —
> o membro esperaria a performer escrever primeiro (para pagar menos), e a performer,
> percebendo isso, pararia de escrever. O preço igual nos dois lados mantém os dois
> dispostos a iniciar.

### 5.3 Quanto a performer recebe

A performer recebe **80% do que o membro pagou pela abertura**: 1,60 token quando o
custo foi 2, ou 0,80 token quando foi 1 (Black/FC).

> **Por que 80/20 (mudança de 19/08/2026):** antes, o chat pagava um valor FIXO de 1
> token à performer, independente do custo. Sobre uma abertura de 2 tokens, isso era
> **50%**, enquanto o contrato com a performer promete **80%**. Era a única retenção
> sistemática indevida da plataforma. Agora o chat segue a mesma regra de 80/20 de
> todos os outros fluxos, e a promessa de 80% passou a ser verdade também aqui.

### 5.4 Franquia diária da performer

A performer pode iniciar conversas com membros **de graça**, até um limite de **15
mensagens grátis por dia** (limite por performer, não por par). O membro que recebe
vê **que** recebeu e **de quem**, mas só lê o conteúdo depois de pagar a abertura
(os mesmos 2 tokens da seção 5.2). **A performer não paga para enviar; só o membro
paga para ler.**

> **Por que 15/dia:** é o limite anti-spam adequado. O freio principal contra abuso
> não é essa cota, e sim o custo que o próprio membro paga para ler. Decisão do dono
> do produto; não baixar sem nova decisão.

### 5.5 Retenção da conversa (o que acontece depois dos 30 dias)

- **Dias 1 a 30:** acesso pleno — o membro lê e escreve.
- **Dias 31 a 45 (carência de 15 dias):** o membro ainda vê a conversa, mas o texto
  fica bloqueado e ele não pode escrever, até renovar o acesso (pagando de novo).
- **Depois do dia 45:** as mensagens são arquivadas (deixam de aparecer). Se o membro
  pagar de novo DENTRO da carência, recupera o histórico; se pagar DEPOIS do
  arquivamento, começa uma conversa nova, sem o histórico antigo.

> **Ponto a revisitar:** quando a conversa é arquivada, **a performer também perde o
> histórico** (as mensagens somem para os dois lados ao mesmo tempo). Isso é
> intencional por ora — a conversa é adulta e a retenção curta reduz risco —, mas é um
> ponto para reabrir **se houver reclamação** de performers. Registrado em
> `docs/DECISOES_2026-08.md`.

---

## 6. Conteúdo permanente (fotos e vídeos)

- A performer publica uma peça, escolhe o **nível** (Aberto, Premium, Exclusivo ou FC
  Only) e o **preço em tokens**.
- **Quem pode COMPRAR cada nível** (ver tabela da seção 3.1): Aberto — grátis para
  qualquer assinante, pago para não-assinante; **Premium — QUALQUER membro compra
  avulso, pagando o preço cheio** (desde 21/08/2026); Exclusivo — a partir de Black;
  FC Only — só FC.
- **O desbloqueio é permanente:** uma vez comprado, o membro vê aquela peça para
  sempre.
- **Divisão: 80% performer / 20% Limen.**

**Todos os níveis APARECEM no perfil, mesmo os que o membro não pode comprar** (mudança
de 21/08/2026). Antes, uma peça acima do tier do membro **sumia** da galeria — ele nem
sabia que existia, o que escondia o valor da assinatura em vez de protegê-lo. Agora:

- **Premium:** aparece com o preço e um botão de comprar, para qualquer membro.
- **Exclusivo / FC Only:** aparecem **bloqueados**, com a indicação do Círculo que os
  destrava ("Disponível no Black" / "Disponível no Círculo de Fundadores") e um caminho
  para assinar. **Não são compráveis avulso** — seguem exclusivos do tier.
- **A imagem real nunca é entregue a quem não pagou.** O tile bloqueado mostra um
  espaço reservado (placeholder), nunca a foto original com um filtro — o bloqueio é
  feito no servidor, não por efeito visual que qualquer um removeria.

> **O incentivo do assinante passou a ser o desconto, não o bloqueio.** Como o Premium
> agora é comprável por todos, o benefício de assinar é pagar os tokens mais barato (o
> desconto na compra de pacotes), não ter acesso exclusivo ao Premium. Exclusivo e FC
> Only continuam sendo o acesso que só o tier dá.

---

## 7. Live pública

- A performer define **X tokens por bloco de 10 minutos**. **Todos pagam** para
  assistir (inclusive assinantes, usando tokens inclusos ou comprados).
- **Divisão da live: 70% performer / 30% Limen.**
- **Gorjeta e presente durante a live seguem a regra do próprio tipo** (gorjeta 80/20,
  presente 75/25), não a da live.

---

## 8. Chamada privada e agendamento

### 8.1 Chamada 1 a 1

- A performer define **X tokens por minuto**. **Todos pagam.** A cobrança é por minuto,
  pré-paga (o saldo nunca fica negativo).
- **Divisão: 70% performer / 30% Limen.**

> **Custo de infraestrutura (live e chamada):** o vídeo em tempo real roda em serviço
> de terceiro, com plano que escala por fase (grátis no lançamento; pago mensal no
> crescimento; servidor próprio na escala). O custo real por participante é da ordem de
> **um centavo por minuto**, o que deixa a divisão 70/30 com margem confortável
> (acima de 98%). É informação de custo/operação, não uma regra que o usuário vê.

### 8.2 Chamada agendada (com depósito)

Em vez de pedir a chamada na hora, o membro pode agendar dia e hora. No ato do
agendamento, o sistema **trava um depósito** equivalente ao preço de 1 minuto (com o
preço/minuto congelado naquele momento). O que acontece com o depósito:

- **Chamada acontece normalmente:** o depósito paga o primeiro minuto (divisão 70/30);
  os minutos seguintes são cobrados por minuto, como na chamada normal.
- **Membro cancela a tempo, a reserva não é confirmada, ou a PERFORMER não aparece:**
  o depósito é **devolvido 100% ao membro**. É devolução, não ganho — não é sacável e
  não conta como receita.
- **O MEMBRO não aparece (no-show):** o depósito vai **100% para a performer**, como
  compensação pelo horário reservado. É ganho sacável.
- **Performer que confirma e falta** leva um **strike**; **três strikes** levam a conta
  para revisão da administração.

---

## 9. Gorjetas e presentes

- **Gorjeta:** valor livre em tokens. **80% performer / 20% Limen.**
- **Presente virtual:** catálogo fixo da Limen, com preços em múltiplos de 4 tokens.
  **80% performer / 20% Limen** (desde 21/08/2026 — antes era 75/25; ver a regra final
  logo abaixo). O caminho decimal cobre frações: um presente de 4 tokens (Rosa) credita
  3,2000; múltiplos maiores fecham redondo (Champagne 40 → 32).

Catálogo de presentes: Rosa 4 · Chocolate 12 · Champagne 40 · Joia 100 · Coroa 200 ·
Diamante 400 tokens.

---

## 10. Destaque no catálogo (boost) e Interesse revelado

Duas formas de monetização **100% da Limen** (não creditam performer nenhuma):

- **Destaque (boost):** a performer gasta **50 tokens** para o perfil dela aparecer no
  topo do catálogo por **6 horas**. A receita vem de ela precisar **comprar** tokens
  para gastar. Há um teto de **20 destaques simultâneos** na plataforma (evita que o
  topo do catálogo vire só destaques pagos).
- **Interesse revelado:** o membro paga **15 tokens** para desbloquear um sinal de
  interesse. **100% da Limen.**

---

## 11. Tokens inclusos e teto de acúmulo

- **Tokens inclusos** (da franquia mensal) são creditados no 1º dia do ciclo, **não
  expiram**, e entram no saldo normal.
- **Teto de acúmulo:** o saldo tem um teto que serve de incentivo a gastar, não de
  confisco. O teto é **5.000 tokens** para todos, exceto **FC, que é 8.000**.
- **O teto vale para ENTRADAS de dinheiro novo, não para ganhos.** Respeitam o teto:
  compra de pacote, bônus e a franquia mensal da assinatura. **Não** respeitam o teto
  (podem ultrapassá-lo): gorjeta recebida, crédito de chat, devoluções, ajustes — tudo
  que é ganho ou correção. Ter saldo acima do teto é um estado legítimo.
- **Fila de pendência:** se a franquia mensal não couber inteira sob o teto, o sistema
  credita o que couber e **pendura o resto** numa fila. A pendência não expira e é
  consumida automaticamente conforme o membro gasta e abre espaço. A pendência máxima é
  de uma franquia (um ciclo novo substitui, não empilha).
- **Compra que ultrapassaria o teto é barrada no checkout.**
- **Aviso de aproximação:** o sistema avisa o membro quando o espaço restante sob o
  teto fica pequeno (em torno de duas franquias do tier, ou 4.500 tokens para quem não
  assina), para ele gastar antes de perder crédito novo por falta de espaço.

---

## 12. O nível do membro é invisível para a performer

A performer **não vê** o Círculo do membro nem se ele é assinante. A única exceção é o
desbloqueio de conteúdo **FC Only** — aí ela sabe que é FC, porque só FC alcança esse
nível.

> **Por quê:** proteger a privacidade do membro. *Mostrar essa informação depois é
> fácil; tirá-la depois de já ter mostrado é impossível.*

---

## 13. Precisão e arredondamento (a regra única)

O valor que a performer recebe pode ter **casas decimais** (ex.: 80% de 3 tokens =
2,40; 80% de 7 = 5,60). A plataforma guarda esses valores com **precisão exata** (até
quatro casas), sem arredondar em nenhuma transação.

> **Por que exato:** com arredondamento a cada transação, um conteúdo de 7 tokens ou
> uma gorjeta de 13 truncariam frações que pertencem à performer. Guardar o valor
> exato garante que a promessa de 80% seja verdade em qualquer preço, não só nos
> preços "redondos". *"Nunca usar número quebrado (float)" continua valendo — o valor
> é decimal exato, não um número aproximado.*

**As quatro regras de arredondamento (valem para todo fluxo, atual e futuro):**

1. **O registro de movimentos nunca arredonda.** Todo lançamento guarda o valor exato.
2. **O arredondamento acontece UMA única vez: na hora do saque**, ao converter tokens
   em reais, e sempre **para baixo** (nunca para cima). Cada token vale R$ 0,60 no
   saque; o valor pago é o inteiro de centavos abaixo do total.
3. **A sobra do arredondamento não some.** O saque só desconta do saldo os tokens que
   os centavos efetivamente pagos representam; a fração que sobrou **continua no saldo
   da performer** para o próximo saque. Exemplo: um saldo de 4,8733 tokens vale R$
   2,923…; paga-se R$ 2,92 (arredondado para baixo), consomem-se 4,8666 tokens, e os
   0,0067 restantes ficam com a performer. Como o desconto é sempre para baixo, **a
   Limen nunca paga menos do que desconta** — o arredondamento nunca favorece a
   plataforma.
4. **Nenhum fluxo cria nem destrói token** (o que sai do membro = o que a performer
   recebe + o que a Limen retém, sempre).

> **Por que "para baixo" e por que preservar a sobra:** para que o arredondamento
> nunca vire uma retenção escondida a favor da plataforma. Arredondar para baixo e
> guardar a sobra garante que, ao longo do tempo, a performer recebe exatamente o que
> ganhou, centavo a centavo.

---

## 14. Saque (payout)

- **Cada token vale R$ 0,60 no saque**, sempre, independentemente de como foi ganho.
- **Duas formas de sacar convivem:** um saque automático mensal (no dia 1, referente
  ao mês anterior) **e** um saque sob demanda a qualquer momento.
- **Valor mínimo de saque: 100 tokens**, nas duas formas. Pago via PIX.
- **Só ganhos são sacáveis.** A performer só saca o que **ganhou** (gorjetas, aberturas
  de chat, conteúdo, presentes, chamadas). Tokens que ela **comprou**, ganhou de bônus
  ou recebeu como franquia de assinatura **não** são sacáveis.

> **Por que só ganhos:** sacar tokens comprados/de franquia a R$ 0,60 seria transformar
> a plataforma num caixa de saída de dinheiro — a pessoa compraria tokens a R$ 1,00 e
> sacaria a R$ 0,60, ou converteria a franquia da assinatura em dinheiro. Só o que foi
> **ganho** de terceiros é sacável.

**Redação obrigatória na interface da performer:** *"Você recebe 80% dos tokens da
transação. Cada token vale R$ 0,60 no saque."* Nunca escrever uma porcentagem sobre o
valor em reais.

---

## 15. Margem mínima

**Nenhuma transação pode resultar em margem bruta abaixo de 25%.** O piso de custo
efetivo do token é **R$ 0,625**. Se um dia mudarem o valor do saque, uma divisão ou um
desconto, é obrigatório recalcular a margem antes de aplicar.

---

## Apêndice — o que este documento substitui

Este documento consolida, em linguagem de negócio, as regras que antes viviam
espalhadas no guia técnico do projeto (as emendas conhecidas internamente como
"M.1 a M.14" e a "regra única de arredondamento"). O guia técnico agora aponta para
cá. As decisões de produto tomadas na virada de agosto/2026 (incluindo a remoção do
portão de conversa e o chat 80/20) estão detalhadas em `docs/DECISOES_2026-08.md`. As
questões que ainda dependem de orientação jurídica estão em
`docs/PENDENCIAS_JURIDICAS.md`.
