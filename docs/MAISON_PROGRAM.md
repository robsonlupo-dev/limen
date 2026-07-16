# Programa Maison — Hierarquia de Performers

Este documento define a hierarquia de performers do Limen, o processo de seleção Maison ("A Banca") e os programas físicos e exclusivos associados.

---

## Hierarquia de performers

### Performer Verificada
- **Entrada:** KYC aprovado
- **Visível para:** todos os membros
- **Taxa:** 20%
- Economia de tokens intacta: fotos, vídeos PPV, 1:1, gorjetas, lives — tudo por tokens
- **Acesso de membros:** qualquer círculo pode comprar tokens para interagir

### Performer Select
- **Entrada:** candidatura ou convite + score 70–84
- **Score calculado:**
  - Qualidade do conteúdo — 25%
  - Comunicação — 20%
  - Consistência — 20%
  - Profissionalismo — 15%
  - Engajamento — 20%
- **Taxa:** 17%
- Badge Select no perfil
- Destaque no catálogo acima das verificadas
- Acesso preferencial no Interesse Controlado para Prestige+
- Visível preferencialmente para Prestige, Black, FC

### Performer Maison
- **Entrada:** EXCLUSIVAMENTE por convite de Robson e Bruno
- **Score mínimo:** 85+ no sistema + aprovação na Banca
- **Vagas:** máximo 50 simultâneas (nunca divulgado publicamente)
- **Taxa:** 12%
- Badge Maison animado
- Sala Maison (networking entre performers Maison)
- Relatório mensal de performance
- Gerente dedicado
- Participa de FC Sessions, FC Collection, FC First Look
- Programa Limen Mementos (pode enviar presentes físicos para membros FC)
- **Exclusive Circle:** conteúdo/lives/sessões exclusivas para Black e FC

---

## O processo Maison — "A Banca"

**Etapa 1 — Score automático 85+**

**Etapa 2 — Período de observação (30 dias)**
A performer não sabe que está sendo observada.

**Etapa 3 — Entrevista por vídeo (30 min)**
Uma conversa, não uma entrevista formal. Perguntas reveladoras:
- "Me conta quem é você além da plataforma."
- "Como você imagina a experiência ideal de um membro que te acessa?"
- "Já teve situação difícil com membro? Como resolveu?"
- "O que você oferece que ninguém mais oferece?"
- "Como se sentiria sendo uma das poucas performers Maison?"

Score de entrevista: **0–20 pontos adicionais**.

**Etapa 4 — Período de prova (60 dias)**
Badge "Em avaliação Maison" visível para Black/FC.

**Etapa 5 — Convite formal**
Carta digital assinada por Robson e Bruno.

### Dimensões avaliadas (não é aparência — é presença)
- Comunicação e escrita
- Inteligência emocional
- Consistência e profissionalismo
- Diferenciação (o que a define)
- Postura e elegância (no sentido de clareza, não vaidade)

### Escalabilidade da Banca
A partir do **6º mês de operação**, a Banca pode incluir um **Conselho Maison**:
Robson + Bruno + 1 Curador contratado.

- Depois dessa fase, **os fundadores não precisam estar presentes em todas as
  entrevistas** — o Conselho conduz
- O convite formal (Etapa 5) segue assinado por Robson e Bruno

---

## Exclusive Circle

> Não é exclusividade de performer — é **exclusividade de experiência**.

A performer Maison cria para Black e FC:
- Lives exclusivas mensais (não disponíveis para outros)
- Álbum exclusivo (conteúdo que não existe fora do Exclusive Circle)
- Chat direto sem desbloqueio de tokens
- Sessão mensal reservada

---

## FC Sessions
- Live mensal
- 20 vagas
- Apenas FC
- Performer Maison escolhida pela plataforma
- **Ephemeral para os membros:** sem replay, sem acesso posterior, por design

### Cofre legal
A sessão é efêmera para membros e performers — ninguém na plataforma assiste de novo.
A plataforma, porém, mantém **gravação em cofre interno por 90 dias**:

- **Único uso admitido:** investigação policial ou denúncia formal
- **Nunca divulgada**, nunca usada para moderação de rotina, marketing, treinamento de
  modelo, curadoria ou qualquer outro fim
- Expurgo automático em 90 dias
- Acesso registrado em audit log

> A gravação existe para proteger a performer e a plataforma numa acusação — não para
> assistir. "Ephemeral" descreve a experiência do membro, não a retenção técnica.

### ⛔ TRAVADO — decisão jurídica pendente
**A gravação backend das FC Sessions exige decisão jurídica antes de qualquer código.
NÃO implementar até aprovação jurídica.**

O cofre acima descreve a **intenção**, não um sistema aprovado para construção.

**Motivo — transparência:** é dado sensível de **vida sexual (art. 11, LGPD)** retido
sem transparência explícita nos termos. Membro e performer entram na sessão hoje
acreditando que nada é gravado; a ressalva vive neste doc interno, não nos termos.

**Base legal:** consentimento provavelmente **não** é base suficiente — o titular pode
revogar, e o cofre precisa **sobreviver à revogação** para servir de defesa numa
acusação. A base correta é decisão jurídica, não de engenharia.

**Infraestrutura necessária que não existe hoje:**
- Criptografia de vídeo em **streaming** (o `Crypt`/`APP_KEY` do KYC carrega o arquivo
  inteiro em memória — serve para JPEG, não para uma live de horas)
- **Modelo de roles** que comporte o Curador (hoje `role` é `consumer|performer|admin`:
  dar acesso ao Curador = dar `admin` = dar KYC + waitlist + cofre de brinde)
- **Expurgo automático verificável** (não existe retenção em lugar nenhum, e o backup
  guarda cópias que o dia 90 não alcança)
- **Audit log de leitura** (o audit atual cobre escrita; no cofre a leitura é o evento
  que importa)

## FC Collection
Conteúdo criado exclusivamente para a coleção FC. Nunca liberado, nunca reutilizado.

## FC First Look
30 dias FC exclusivo → 30 dias Black → Prestige → resto.

---

## Limen Mementos (apenas Maison → membros FC)
- A performer decide **espontaneamente** enviar — zero obrigação
- O membro ativa "Aceito receber Mementos" + Locker/Caixa Postal (**nunca endereço real**)
- A plataforma intermedeia: performer envia para o hub Limen → Limen fotografa/aprova → reenvia para o Locker
- Sem promessa sobre o que é enviado — é uma **lembrança, não produto**
- Curadoria obrigatória pela plataforma antes do reenvio
- Máximo **1 memo/mês** por performer

### Elegibilidade
- Apenas membros **FC** com **"Aceito receber Mementos"** ativado nas configurações
- **Black e abaixo não recebem Mementos físicos** — em nenhuma hipótese
- O toggle é o **único** sinal de disponibilidade do membro

### O gesto nunca é transação
- O membro **sinaliza disponibilidade, mas NUNCA solicita**
- Não existe pedido, fila, catálogo ou insinuação de Memento
- A performer decide espontaneamente quando (e se) enviar
- **O gesto sempre é surpresa — nunca transação**

### Custo logístico
**800 tokens fixos** cobrados do membro FC para ativar o processo logístico de cada
Memento. Cobre frete + operação do hub.

- Valor **fixo**, independente do que foi enviado ou de onde
- Retido **100% pela plataforma** — é custo operacional, não receita da performer
- Não é preço do presente: o Memento em si não tem preço, e pagar não o compra

### Reserva de tokens
Os 800 tokens são **reservados (hold no ledger)** no momento em que o Limen
**aprova a foto do item** — **não** na chegada ao hub.

- O membro vê **imediatamente** o saldo comprometido
- **Foto reprovada:** a reserva é **liberada automaticamente**
- **Débito definitivo:** na confirmação de recebimento no hub

A reserva na aprovação (e não no envio) fecha a janela em que o membro poderia
gastar os tokens enquanto o pacote está a caminho — o frete só sai da casa da
performer com o valor já comprometido.

### Saldo insuficiente
A verificação ocorre **antes de a performer submeter a foto** — nada é fotografado,
aprovado ou enviado se o membro não tiver saldo.

- **Saldo < 800 tokens:** a performer vê a mensagem genérica
  *"Não é possível enviar para este membro no momento"*

**A mensagem é idêntica para qualquer tipo de bloqueio** — saldo, configuração
desativada, limite mensal. A performer **nunca descobre o motivo real**.

> Isto é deliberado: protege a **privacidade financeira do membro**. Uma mensagem
> específica ("sem saldo") entregaria à performer o estado da carteira dele, e uma
> mensagem distinta por motivo permitiria deduzir, por eliminação, que o membro
> desligou o toggle. Mesma doutrina da máscara do opt-out no Interesse Controlado:
> se os motivos são distinguíveis, o bloqueio vira um canal de informação.

---

## A Chave do Portal (6 meses de FC ativo)
- **Objeto físico:** chave banhada em ouro, gravada com o número FC, em caixa de veludo preto
- **Frente:** símbolo do Limen (arco dourado) / **Verso:** FC-007 gravado
- **Cartão interno:** apenas *"Bem-vindo ao Portal."* — sem mais texto
- **Chegada surpresa** — o membro não sabe quando vem
- Fica com o membro mesmo se cancelar (lembrete permanente do que perdeu)
- Reposição possível, mas marcada como "FC-007 · Reposição"

### Régua completa de marcos físicos
- **3 meses:** cartão físico com o símbolo Limen
- **6 meses:** A Chave do Portal
- **1 ano:** objeto surpresa
- **2 anos:** placa numerada "Fundador FC-007 · Desde 2027"

---

## Casais
- **KYC:** os dois verificam identidade
- **Contrato:** os dois assinam
- **Split de receita:** o casal define no cadastro (cada um saca para sua chave PIX)
- **Banca Maison para casais:** presença dos dois; a avaliação inclui a dinâmica/química entre eles
- **Badge:** "Casal Maison"
