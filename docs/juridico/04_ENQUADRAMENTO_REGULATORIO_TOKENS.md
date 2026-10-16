# Enquadramento regulatório dos créditos (tokens)

> **Natureza e limites.** Análise **preliminar**, elaborada pela engenharia/PO para **orientar o advogado**. **Não é parecer.** As normas e números devem ser **conferidos na redação vigente**. Parte desta análise complementa o parecer já obtido (Monteseiler, 09/10/2026), que concluiu pela "blindagem eficaz" da tese do token como **passivo circulante** e do repasse às Performers como **repasse** (não receita da Limen). Esta nota **não substitui** esse parecer: lista o que precisa ser **ratificado ou atualizado** e as perguntas que restam.

---

## 1. Como o token funciona (fatos)

| Aspecto | Regra atual |
|---|---|
| Natureza | Crédito de uso na plataforma, em unidades internas ("tokens") |
| Compra | Pacotes pagos por **PIX** (e franquia mensal das assinaturas, paga por cartão) |
| Referência de preço | Starter: R$ 1,00/token; pacotes maiores têm desconto; piso interno de R$ 0,625 |
| Validade | **Não expira** por tempo (regra atual) |
| Teto de saldo | 5.000 tokens (8.000 no Founders Circle) para **entradas** de dinheiro novo; ganhos não respeitam o teto |
| Transferência entre usuários | **Não existe**: o Membro só **gasta** na plataforma; a Performer só **recebe** de gastos |
| Resgate do Membro em dinheiro | **Não** (exceto arrependimento de 7 dias e falha da plataforma) |
| Ganho da Performer | Parte dos tokens gastos (80% ou 70%), **sacável** a **R$ 0,60/token**, via PIX, **só ganhos** |
| Tokens de bônus/franquia/indicação | **Não sacáveis** |
| Contabilidade | **Livro de lançamentos imutável** (append-only); saldo = soma dos lançamentos; decimais exatos (4 casas) |
| Fluxo do dinheiro | Membro → Limen (PIX) → saldo em tokens; gasto move tokens; Limen → Performer (PIX do saque) |
| Recebedor legal | **Limen** (a Performer é paga em operação posterior) |

---

## 2. Hipóteses de enquadramento consideradas

### 2.1. Moeda eletrônica / arranjo e instituição de pagamento (Lei 12.865/2013 e normas do Banco Central)

**Pergunta:** o token é "recurso mantido em meio eletrônico que permite a execução de transação de pagamento" (moeda eletrônica) — o que sujeitaria a Limen à autorização do BCB como instituição de pagamento?

**Elementos contra o enquadramento:**
- **Circuito fechado**: o token só compra bens e serviços **da própria plataforma** (conteúdo, chat, live, chamadas, gorjetas) — ele não é aceito fora dela.
- **Sem transferência P2P** nem saque pelo Membro.
- **Sem equivalência garantida** com moeda (compra a R$ 1,00; saque da Performer a R$ 0,60).
- **Sem rendimento**.
- **Só ganhos são sacáveis**, o que impede o uso do sistema como "caixa" de conversão de dinheiro (comprar a R$ 1,00 e sacar a R$ 0,60 é economicamente irracional).

**Elementos que exigem atenção:**
- Há **saque em dinheiro** pela Performer: a Limen recebe do Membro e **paga** a um terceiro (a Performer) — o que se aproxima de atividade de **intermediação de pagamento / subcredenciamento** (marketplace). Estruturas comuns de mitigação: (a) **split de pagamento no PSP** (o PSP, autorizado, recebe, divide e paga); (b) contar com **instituição de pagamento parceira** que mantenha as contas; (c) enquadrar como **arranjo fechado** abaixo do limiar de relevância definido pelo regulador *(limiar a confirmar na norma vigente; em geral definido em volume financeiro anual)*.
- O parecer de 09/10 observou que o **split na origem não se aplica à carteira pré-paga** (no ato da compra ainda não há performer de destino), mas se aplica às compras diretas (PPV, encomenda), onde o provedor (Woovi) cobre.

**Perguntas ao advogado:**
1. O desenho atual (carteira pré-paga + repasse posterior) é **arranjo fechado dispensado de autorização**? Qual o **limiar de volume** em vigor e como monitorar?
2. É recomendável **migrar o repasse para o PSP (split/subconta)**? A partir de que volume?
3. Como evitar o enquadramento como **subcredenciador**?

### 2.2. Criptoativo / ativo virtual (Lei 14.478/2022)

O token da Limen é **registro em banco de dados interno**, sem *blockchain*, sem negociação em mercado, sem transferência P2P. **Tende a não se enquadrar** como ativo virtual. **Decisão registrada:** a Limen **não** emitirá tokens em blockchain nem permitirá negociação externa; isso mudaria totalmente o enquadramento.

### 2.3. Valor mobiliário (Lei 6.385/1976)

Há "contrato de investimento coletivo" quando o adquirente aplica recursos esperando **lucro** decorrente do esforço de terceiro. O token **não promete rendimento**, não é negociado e é adquirido para **consumo**. **Evitar** qualquer comunicação que o apresente como investimento, que prometa valorização ou que permita revenda.

### 2.4. Crédito pré-pago / vale-serviço e Código de Defesa do Consumidor

- O token é **crédito pré-pago de consumo**. O CDC aplica-se às compras dos Membros: **direito de arrependimento de 7 dias** (art. 49) para compras à distância; vedação de cláusulas abusivas (art. 51), inclusive de **perda total do saldo** sem contrapartida; dever de informação clara (arts. 6º, III, e 31).
- **Expiração:** a regra atual (**sem expiração**) é a mais segura. Se algum dia houver validade, é preciso aviso claro e prazo razoável.
- **Encerramento da conta:** a retenção do saldo em caso de **banimento por violação** é defensável se previsto e proporcional; em encerramento **voluntário**, o saldo não utilizado deve ser tratado com cuidado (reembolso ou conversão). O texto publicado prevê reembolso nos termos do arrependimento.
- Decreto 7.962/2013 (comércio eletrônico): informações claras, canal de atendimento, confirmação de compra, facilidade para arrependimento.

### 2.5. Contabilidade e tributação

- **Contabilmente:** o recebimento antecipado é **passivo** (receita diferida) até o consumo; reconhecimento da receita da Limen = a **parte retida** no consumo.
- **Agente vs. principal:** se a Limen atua como **agente** (intermediária), a receita é a comissão (20–30%); se como principal, o faturamento bruto. A conclusão afeta ISS/PIS/COFINS/IRPJ/CSLL e deve ser definida por **contador/advogado tributário**.
- **Retenção de tributos nos pagamentos às Performers** (IR, INSS, ISS) a depender do enquadramento delas (PF, MEI, PJ). **A Limen precisa de política de documentação fiscal da Performer.**

### 2.6. Prevenção à lavagem de dinheiro (Lei 9.613/1998)

A Limen provavelmente **não** é pessoa obrigada de plano, mas **boas práticas** reduzem risco e exigências do PSP: KYC de ambos os lados (feito), limites e tetos (feito), monitoramento de padrões atípicos (depósitos fracionados, contas ligadas por IP/dispositivo/CPF) e **comunicação** ao PSP/COAF quando aplicável. Trilho de caixa **fechado** (só ganhos saem) é um bom controle.

---

## 3. Cláusulas contratuais (já refletidas nos textos publicados)

- Tokens são **Créditos de Uso**, **não moeda**, **não resgatáveis em dinheiro pelo Membro**, **intransferíveis**, **sem rendimento**, sem equivalência garantida com moeda corrente.
- Arrependimento de 7 dias para tokens **não utilizados** de compras à distância; devolução automática em hipóteses específicas.
- Prescrição quinquenal quando aplicável (CC art. 206, § 5º, I).
- Teto de saldo e razões.
- Para a Performer: R$ 0,60 por token; **só ganhos sacáveis**; floor de centavos com preservação da fração; estornos, retenção cautelar.

## 4. Recomendações práticas

1. **Descritor neutro** na cobrança (não "Limen Conteúdo Adulto").
2. **Split no PSP** quando disponível (Woovi oferece; Efí/Transfeera a confirmar).
3. **Nunca** permitir transferência de tokens entre usuários, saque pelo Membro, revenda ou emissão em *blockchain*.
4. **Limites de saque e de compra** como controle antifraude.
5. **Relatório mensal** de conciliação (`php artisan uat:conferir-economia`) arquivado para auditoria.
6. Manter o **ledger imutável** como prova.
7. Documentar a decisão de **não** haver expiração.
8. **Contador** antes do CNPJ para definir regime e enquadramento tributário.

## 5. Perguntas para o advogado

1. Ratificar (e atualizar pela ECA Digital) a conclusão de que o token é **passivo** e o repasse é **repasse**?
2. O desenho é **arranjo fechado** dispensado? Qual o limiar?
3. Preciso migrar o repasse para o PSP?
4. A cláusula de **não resgate** é válida frente ao CDC, e como tratar o encerramento voluntário?
5. Como enquadrar o **Fã-Clube** (assinatura em token) e a **franquia** de assinatura?
6. Quais obrigações de **PLD/COAF** pesam sobre a Limen?
