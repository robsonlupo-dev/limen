# Pendências jurídicas

Registro de questões que dependem de orientação de um advogado. **Este documento não
decide nada** — descreve o que existe hoje e formula a pergunta para o jurídico. Nada
aqui deve ser tratado como parecer legal.

---

## 1. O filtro de conteúdo do chat e a troca de contato

### O que o filtro faz hoje

As mensagens do chat passam por um filtro automático. Ele bloqueia **duas** categorias:

1. **Intermediação de programa pago fora da plataforma.** Bloqueia frases que combinam
   um encontro/serviço pago com um sinal de dinheiro na mesma mensagem — por exemplo,
   propor "programa" e citar um valor em reais, ou combinar pagamento "por fora" da
   plataforma. Também bloqueia expressões inequívocas de serviço sexual pago.
2. **Ameaça e insulto direcionado.** Bloqueia ameaças, chantagem/extorsão (inclusive
   ameaça de vazar fotos) e xingamentos claramente dirigidos a alguém. Palavrão em
   contexto sexual consentido ("dirty talk") **não** é bloqueado — é o vocabulário
   esperado numa plataforma adulta.

### O que o filtro DELIBERADAMENTE NÃO bloqueia

- **Troca de contato.** Telefone, e-mail, endereço, redes sociais (WhatsApp,
  Instagram, etc.) **passam livremente**. A decisão de produto foi permitir isso, por
  ser considerado legítimo numa plataforma de conteúdo adulto / relacionamento.
- **Combinar encontro sem valor monetário.** "Vamos a um motel" passa; só bloqueia se
  vier acompanhado de um valor em dinheiro.

> **Observação importante:** o filtro **não é** uma barreira à prova de evasão, e nunca
> se propôs a ser. Ele reduz os casos óbvios; duas pessoas determinadas conseguem
> contornar. Não deve ser descrito, em termos de uso ou auditoria, como garantia de
> que nada ilícito é combinado.

### Perguntas para o advogado

- **Há responsabilidade da plataforma se dois usuários trocam contato dentro do chat e
  combinam, fora dela, um encontro pago?** O filtro permite a troca de contato de
  propósito; a combinação do encontro pago pode acontecer fora da nossa vista (por
  outro canal). Qual é a exposição da plataforma nesse cenário, e o que os termos de
  uso e a política de conteúdo precisam dizer para delimitar essa responsabilidade?
- Precisamos registrar/reter algo especificamente por causa disso, ou o contrário —
  reter menos?

---

## 2. Retenção de conversa adulta

### O que existe hoje

As conversas têm retenção curta: acesso pleno por 30 dias, mais 15 dias de carência,
e depois as mensagens são arquivadas (deixam de aparecer para os dois lados). Detalhe
em `docs/ECONOMIA.md`, seção "Conversa".

### Pergunta para o advogado

- Essa política de retenção é adequada do ponto de vista legal (guarda de registros,
  eventual requisição judicial, LGPD)? Precisamos guardar mais tempo, menos tempo, ou
  guardar de forma diferente (por exemplo, manter um registro mínimo mesmo depois do
  arquivamento visível)? **O que os termos de uso precisam dizer** sobre o que é
  guardado, por quanto tempo, e o que o usuário pode pedir para apagar.

---

## 3. Documentos e formalização pendentes

Itens que precisam do jurídico antes do lançamento (sem os quais a plataforma não deve
abrir ao público):

- **CNPJ** e a estrutura societária/fiscal da operação.
- **Termos de uso reais** (hoje há apenas texto provisório).
- **Contrato da performer** (o contrato de quem publica conteúdo — hoje provisório).
- **Política de privacidade** (tratamento de dados, LGPD, subprocessadores).
- **Política de conteúdo proibido** (o que não pode ser publicado, e as consequências).

> **Nota:** enquanto os textos definitivos não entrarem, não se deve descrever, em
> auditoria ou comunicação, que existe "contrato aceito" ou "termos aceitos" — o que há
> é texto provisório aguardando o jurídico.

---

## 4. Apelido do membro — validação mais rígida que a do chat

### O que existe hoje

O membro pode escolher um **apelido** público (opcional), que a performer passa a ver no
lugar do "Fã #NNNN" (ver `docs/DECISOES_2026-08.md` §17). Por ser um campo **público e
permanente**, ele tem validação PRÓPRIA, deliberadamente **mais rígida** que a do filtro
de chat: barra telefone (5+ dígitos consecutivos após remover separadores), e-mail/@,
URL/domínio, nome de rede social (com anti-desvio de leet/repetição), palavra reservada da
plataforma (limen/suporte/admin/moderador/oficial…), **nome de performer existente**
(anti-personificação) e conduta abusiva. Dona única: `App\Services\MemberNicknameService`.

### Por que é diferente do chat — e a pergunta ao jurídico

O filtro do chat, por decisão de produto, **NÃO barra troca de contato** (é legítimo entre
dois adultos numa conversa privada). O apelido é o oposto: é um rótulo público, visto por
todas as performers e pelos outros membros no chat de uma live — um telefone ali seria
difusão de contato, não conversa privada. Daí a assimetria.

**Perguntas em aberto para o jurídico:**

1. O limite adotado para "telefone disfarçado" — **5 dígitos consecutivos** — é adequado,
   ou deve ser mais baixo? (Um DDD+número real tem 10–11 dígitos; 5 já pega a maioria dos
   disfarces sem barrar um apelido legítimo como "leo2000".)
2. A recusa de apelido igual a **nome artístico de performer** (anti-personificação) é
   suficiente do ponto de vista de direito de imagem/marca, ou é preciso também reservar
   nomes de figuras públicas / marcas de terceiros?
3. A **política do chat** (não barrar troca de contato) **segue pendente de parecer** — o
   apelido não a altera; apenas não herda a permissão dela por ser superfície pública.

> **Nota:** a validação do apelido reduz o risco de contato/personificação, mas **não é
> garantia** — apelido malicioso que passe pela heurística é tratado por **moderação**
> (remoção forçada; denúncia pública é PR futuro). Mesma disciplina de linguagem do painel
> de visitantes e do filtro de chat: não descrever como "impede" contato, e sim "dificulta".

---

## 5. Evasão do filtro de chat por espaçamento — CORRIGIDA

### O que era a falha

A revisão de segurança do apelido (§4) descobriu que a normalização compartilhada do
filtro **preservava espaços**, e o casador de termos não tolerava separador entre as
letras de uma mesma palavra. Um desvio trivial — intercalar espaço (ou ponto/hífen/
sublinhado) entre as letras — **furava o filtro por completo**. Passavam mensagens que as
regras vigentes já mandavam bloquear (não era política de contato — era o filtro falhando
na função que já tinha):

- Intermediação de programa pago / transação fora da plataforma: `f a z e r  p r o g r a m a`,
  `p r o g r a m a  c o m p l e t o, 300 r e a i s`, `p i x  f o r a`.
- Ameaça: `t e  m a t o`, `s e i  o n d e  v o c e  m o r a`.
- Insulto direcionado: `s u a  p u t a  n o j e n t a`, `sua p u t a n o j e n t a`.

### O que foi corrigido (`fix/chat-filter-space-evasion`)

O casador de termos passou a tolerar um separador de evasão (espaço, ponto, hífen,
sublinhado) **entre as letras de uma mesma palavra** — sem achatar a mensagem inteira, o
que criaria falso positivo. A lacuna **entre palavras** de uma frase continua exigindo
espaço de verdade, então mensagem legítima com pontuação (ex.: "vou fazer o pix. **fora**
disso, tudo bem") **não** é barrada por engano. Todos os exemplos acima agora bloqueiam;
o desarme por qualificador consensual ("sua puta safada") e a decisão de contato seguem
intactos. Coberto por testes em `tests/Feature/ChatContentFilterTest.php`.

**Resíduo conhecido e aceito (não é falha nova):** quando o desvio usa o **mesmo**
separador não-branco entre as letras **e** no lugar do espaço entre as palavras — sem
nenhum whitespace marcando a fronteira (ex.: `te.mato`, `t.e.m.a.t.o`) — a mensagem
escapa. Isso é **estruturalmente idêntico** a "pix. fora", que **deve passar**: não há
como casar um sem barrar o outro. A rede contra o que escapa continua sendo a **moderação
por denúncia**. Mantida a disciplina de linguagem: o filtro **dificulta**, não **impede**.

### O que isto muda para o jurídico

**A pergunta que PERMANECE em aberto é apenas a política de troca de contato (§1)** —
telefone/e-mail/rede social seguem liberados por decisão de produto, pendentes de parecer.
A **evasão por espaçamento não é mais uma questão em aberto**: era um defeito de
implementação, e foi corrigido. Não há aqui nova decisão de produto nem de política a
tomar.

---

## 6. Perfil do membro v2 — diretório, galeria e dados pessoais (`feat/member-profile-v2`)

O membro passou a ter um **perfil rico** que a performer vê: bio, "o que busco", interesses,
cidade/UF, estado civil, altura, faixa etária e galeria de fotos. **Tudo é opcional e
opt-in** (o membro escreve; nada aparece sem ele preencher **e** ligar "Perfil visível", que
segue **default OFF**). Quatro blocos precisam de orientação jurídica.

### (a) Virada opt-in → opt-out do diretório de membros — DECIDIDA pelo PO, aguardando o jurídico

- **O que existe hoje.** O membro só aparece para as performers se ligar `profile_visible`
  (default OFF). É consentimento **ativo**, por gesto explícito.
- **O que o PO decidiu (não implementado).** Inverter para **opt-out**: o membro apareceria
  no diretório **por padrão**, e a invisibilidade viraria **perk exclusivo** de Founders
  Circle / Black.
- **Perguntas ao advogado:**
  1. Qual a **base legal LGPD** para expor o membro por padrão (consentimento no cadastro vs.
     legítimo interesse)? Um perfil em site adulto é **dado sensível** (art. 5º, II) — o
     legítimo interesse é suficiente ou é preciso consentimento específico e destacado?
  2. Que **texto nos Termos / no fluxo de cadastro** torna esse consentimento válido
     (informado, específico, livre)? Colocar a invisibilidade atrás de um tier pago
     compromete a "liberdade" do consentimento?
  3. Direito de **oposição/eliminação**: o opt-out precisa ser tão fácil quanto o padrão, e
     gratuito?

### (b) Galeria de fotos do membro — consentimento e retenção

- **O que existe hoje.** Até 4 fotos, opt-in, **moderação humana** antes de aparecer, strip
  de EXIF/GPS + anti-CSAM, servidas por token opaco. Foto recusada tem os **bytes purgados na
  hora**; encerramento de conta purga tudo (LGPD Hard Delete). O v2 guarda **duas variantes**
  (enquadrada + completa) — ambas purgadas juntas.
- **Perguntas ao advogado:**
  1. É preciso **consentimento específico** para foto (dado biométrico/sensível — é rosto em
     site adulto), além do consentimento geral do perfil?
  2. **Retenção:** por quanto tempo pode ficar a foto de um perfil que o membro apenas
     **ocultou** (desligou a visibilidade) sem encerrar a conta? Hoje ela **permanece** (o
     interruptor é reversível). Há prazo máximo?
  3. Prova de consentimento e de que "é foto sua" — que registro basta?

### (c) Faixa etária e cidade no perfil

- **O que existe hoje.** A **faixa etária** é **derivada do `birthdate`** do cadastro (nunca
  a data nem a idade exata) e só aparece com **opt-in** (`show_age_band`). A **cidade/UF** é
  o que o membro digita (autocomplete IBGE), e só aparece se preenchida.
- **Perguntas ao advogado:**
  1. Derivar e exibir uma **faixa** a partir do `birthdate` coletado para **outra finalidade**
     (verificação 18+/KYC) é **mudança de finalidade** que exige nova base legal, mesmo com
     opt-in e mesmo sem expor a data?
  2. Cidade + faixa + foto juntos aumentam o risco de **reidentificação**. Há mínimo de
     granularidade a impor (ex.: só a UF, não o município; faixas mais largas)?

### (d) Moderação prévia → automática + reativa — ARQUIVADA, reavaliar pós-lançamento

- **O que existe hoje.** Toda foto passa por **aprovação humana** antes de ir ao ar.
- **O que foi arquivado.** Trocar por **moderação automática + reativa** (a foto apareceria
  antes da revisão humana; a revisão viria depois e por denúncia). **Arquivado** — não
  implementado neste PR.
- **Pergunta ao advogado (para quando/se for reconsiderado):** a moderação **prévia** é
  exigência de compliance (anti-CSAM, imagem de terceiro não consentida) ou uma escolha de
  produto que pode ser afrouxada? Que salvaguardas a automática+reativa precisaria ter para
  ser defensável?

### (e) Revelação de identidade do membro no admin ("quebrar o vidro") — VALIDAR ANTES DE LIGAR EM PRODUÇÃO

- **O que existe hoje (feat/admin-members).** O painel `/admin/membros` é **anônimo por
  padrão**: o admin busca um membro **por ID** e vê só dados de moderação (status, cadastro,
  último login, nº de denúncias contra, blacklist) — **nenhuma PII**. Há uma ação separada
  **"Revelar identidade"** que mostra **nome e e-mail** de UM membro, **uma única vez**, com
  **motivo obrigatório** e **registro de auditoria** (`member.identity_revealed`: quem, quando,
  por quê, qual membro). A identidade **não é persistida** nessa camada nem aparece em lista.
  **CPF e documento continuam fora do app** (painel do provider/Didit), como no KYC.
- **Por que existe.** Necessidade legítima de identificar um membro para **ação jurídica ou de
  segurança** (conteúdo ilegal, ordem judicial, incidente grave). O padrão "break the glass"
  preserva o anonimato como regra e torna o acesso deliberado e rastreável.
- **Perguntas ao advogado (LGPD):**
  1. O acesso auditado a **nome/e-mail** por um admin, com finalidade registrada, é
     **base legal suficiente** (ex.: obrigação legal / legítimo interesse), ou exige mais
     (aprovação de dois fatores humanos, papel dedicado "super-admin", limite temporal)?
  2. É preciso **notificar o titular** da revelação (e em que prazo/exceções — ex.: investigação)?
  3. Por quanto tempo o **log de auditoria** da revelação deve ser retido, e quem pode lê-lo?
  4. A ação deve ser **restrita a papéis específicos** e/ou exigir **registro do nº do processo/
     ofício** quando a finalidade for judicial?
- **Recomendação de engenharia:** manter a ação **desligada por padrão em produção** (feature
  flag/role) até o parecer. O mecanismo (audit trail) já está pronto; falta a decisão jurídica.
