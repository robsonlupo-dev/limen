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
