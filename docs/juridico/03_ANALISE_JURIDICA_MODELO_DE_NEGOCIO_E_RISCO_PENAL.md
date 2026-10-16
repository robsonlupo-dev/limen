# Análise jurídica preliminar do modelo de negócio — inclusive risco penal

> **Natureza e limites.** Esta é uma **análise de risco preliminar**, elaborada pela engenharia/PO a partir do código e da documentação do projeto, para **servir de ponto de partida ao advogado**. **Não é parecer jurídico**, não substitui assessoria e não deve ser apresentada a terceiros como opinião legal. Os dispositivos citados devem ser **conferidos** pelo advogado na redação vigente. Onde há divergência entre o que esta análise afirma e o que o parecer do advogado concluir, vale o parecer. Esta análise **não repete** o parecer já obtido (Monteseiler, 09/10/2026) sobre o token como passivo e a "blindagem" da tese do Art. 228; ela o **referencia** e complementa.
> Fatos técnicos extraídos de `docs/LEGAL_GAP_ANALYSIS.md` (09/10/2026), `docs/ECONOMIA.md`, `docs/PENDENCIAS_JURIDICAS.md` e do código.

---

## 1. O que a Limen faz (fatos relevantes)

1. Plataforma onde **Performers verificadas (KYC: documento + selfie)** publicam **conteúdo adulto gravado**, fazem **transmissões ao vivo** e **chamadas de vídeo**, e **conversam por mensagem/voz** com **Membros também verificados** (maioridade por data de nascimento + CPF + KYC de selfie/documento).
2. Tudo é pago em **tokens** (Créditos de Uso) comprados por **PIX**; a Limen retém **20%** (conteúdo, gorjeta, chat, presente, encomenda, Fã-Clube) ou **30%** (live e chamada) e repassa o restante em tokens à Performer, que **saca a R$ 0,60/token**, via PIX, somente **ganhos**.
3. A plataforma **não oferece, não anuncia, não intermedeia e não cobra** encontros ou serviços sexuais **presenciais**. O filtro do chat **bloqueia** propostas de programa pago, "pix por fora", ameaças e extorsão.
4. O chat **já pago** é livre; a **troca de contato** entre os dois é **permitida por decisão de produto**. As 15 mensagens grátis diárias das Performers são **modelos pré-cadastrados**.
5. **Preço** do conteúdo, live e chamada é **definido pela Performer**; a Limen define apenas valores fixos de plataforma (abertura de conversa, catálogo de presentes, boost) e os percentuais de repasse.
6. **Moderação**: denúncias de membros, fila de moderadores, advertência/suspensão/banimento, congelamento de remoção de prova, KYC, bloqueio por *hash* de CPF.

---

## 2. Quadro resumido de riscos (nível de exposição atual **antes** das medidas pendentes)

Legenda: **A** alto · **M** médio · **B** baixo · **?** depende de parecer.

| # | Tema | Risco | Principal motivo | Medida de maior efeito |
|---|---|---|---|---|
| 1 | Favorecimento da prostituição / rufianismo (CP arts. 228, 229, 230) | **M**→**B** com medidas | Chat com contato livre; prostituição não é crime, mas facilitá-la é; modelo digital reduz o risco | Parecer + textos + moderação ativa de "agenciamento" |
| 2 | Material de abuso sexual infantil (ECA arts. 240–241-E) | **A** (pelo impacto) | Detector CSAM é MVP (lista vazia, só imagem, fail-open) | Integrar base real e cobrir vídeo **antes do 1º upload** em produção |
| 3 | Verificação de idade (ECA Digital, Lei 15.211/2025) | **M**→**A** | Idade ainda autodeclarada + CPF estrutural; KYC por provedor | KYC de ambos os lados + evolução para base oficial |
| 4 | Divulgação não consentida de intimidade (CP art. 218-C, 216-B) | **M** | Performer pode subir conteúdo de terceiros | Consentimento documentado, denúncia prioritária, marca d'água |
| 5 | Tráfico de pessoas / exploração / coerção (CP art. 149-A; Lei 13.344/2016) | **M** | Pode haver terceiros controlando contas | KYC, 2FA, canal de denúncia, detecção de IP compartilhado |
| 6 | Obscenidade (CP art. 234) | **B**/**?** | Dispositivo antigo; aplicação a conteúdo adulto entre adultos é controversa | Parecer |
| 7 | Responsabilidade civil por conteúdo de terceiros (Marco Civil; STF) | **M** | Regime de responsabilidade de provedores em evolução | Notice-and-takedown ágil, políticas, logs |
| 8 | LGPD (dados sensíveis, retenção, KYC) | **M** | Dado sensível por contexto; retenção de mensagens sem base escrita; mensagens em texto puro | Política, base legal escrita, cifra de mensagens |
| 9 | Consumidor (CDC) e tokens | **M** | Saldo, arrependimento, cláusulas abusivas | Cláusulas de token + arrependimento 7 dias |
| 10 | Pagamentos / arranjo / instituição de pagamento | **?** | Limen recebe e repassa; ver doc 04 | Split no PSP, parecer |
| 11 | Tributário | **?** | ISS/PIS/COFINS/IRPJ; agente vs. principal | Contador |
| 12 | Societário/PI | **M** | Sem CNPJ, sem vesting, sem cessão | Docs 01 e 02 |
| 13 | Geobloqueio FOSTA-SESTA (EUA) | **M** | Driver de geo **inativo** | Ligar driver real |

---

## 3. Risco penal — análise por tipo

> Referência: Código Penal (Decreto-Lei nº 2.848/1940) e ECA (Lei nº 8.069/1990). **Conferir a redação vigente de cada artigo.**

### 3.1. Art. 228 — Favorecimento da prostituição ou de outra forma de exploração sexual

**Conduta típica (resumo):** induzir ou atrair alguém à prostituição ou a outra forma de exploração sexual, **facilitá-la**, impedir ou dificultar que alguém a abandone. **Não exige proveito econômico** (diferente do art. 230).
**Por que importa:** o verbo "facilitar" é aberto. É o ponto em que a plataforma poderia ser acusada se fosse vista como meio de **conectar** pessoas para **encontros sexuais pagos**.

**Argumentos favoráveis ao modelo (a serem testados pelo parecer):**
- **Objeto digital**: o que se vende é **conteúdo gravado, transmissão e chamada de vídeo**; não há agenda de encontros, tarifa de "programa" ou endereço; a atividade da Performer é **produção e exibição de conteúdo** a distância.
- **Autonomia das Performers**: preço, conteúdo, horários e recusa são decisões delas; a Limen não impõe roteiro nem metas.
- **Verificação**: Performers e Membros são adultos verificados; a Performer pode **sair a qualquer momento**, sem multa; há canal de **denúncia de coerção**.
- **Barreiras ativas**: o filtro **bloqueia** proposta de programa pago e pagamento "por fora"; a política **proíbe** oferecer encontros presenciais pagos e agenciamento; mensagens grátis são **modelos fixos**; textos públicos barram contatos.
- **Tese do token (parecer 09/10)**: token é passivo da Limen; a parcela da Performer é **repasse**, não "lucro" sobre atividade sexual presencial.

**Pontos fracos / a mitigar:**
- **Troca de contato permitida** no chat pago (e nenhum filtro em voz). Pode ser lida como "facilitar" contato que leve a encontros pagos fora da plataforma. *Mitigação:* ToS e contrato com cláusulas expressas (feito), detecção de **agenciamento** por co-ocorrência de sinais (proposta Nível 1 em `PENDENCIAS_JURIDICAS.md` §9), aviso de segurança ao Membro, e **decisão consciente do produto** documentada (para o advogado, não é lacuna).
- **Filtro não é à prova de evasão** (resíduo conhecido `te.mato`, idem). Nunca descrever como garantia.
- **Moderação reativa** (por denúncia). Ampliar a sinalização automática.
- **Modelo econômico**: a Limen retém 20–30% do consumo. A remuneração por conteúdo digital não é "participar dos lucros da prostituição alheia" *(art. 230)*, mas a redação do material de marketing deve **evitar** sugerir encontro ou "acompanhante".
- **Marketing**: nunca usar termos como "acompanhantes", "programa", "garotas de programa" nos ativos da marca. *(A referência competitiva de mercado — anúncios de acompanhantes — é exatamente o oposto do posicionamento a ser mantido.)*

**Medidas a adotar (sem custo):** manter as políticas publicadas; registrar a decisão de produto sobre contato; registrar logs de moderação; manter **sem presencial** em qualquer superfície. **Com advogado:** parecer F1 ou **ratificação** do parecer existente, com atualização para o desenho atual.

### 3.2. Art. 229 — Casa de prostituição

"Manter, por conta própria ou de terceiro, estabelecimento em que ocorra exploração sexual." Plataforma digital que **não hospeda** encontros presenciais, tende a não se enquadrar; risco aumentaria se houvesse **agenda/localização/hospedagem** de encontros. **Manter a plataforma sem funções de endereço, agenda de encontro presencial, ou reserva de local.** *(Hoje existe "chamada agendada" — **de vídeo**, sempre à distância; reforçar essa natureza na UI e nos textos.)*

### 3.3. Art. 230 — Rufianismo

"Tirar proveito da prostituição alheia, participando diretamente de seus lucros ou fazendo-se sustentar, no todo ou em parte, por quem a exerça." Ponto sensível porque a Limen **retém um percentual** do consumo. Argumento: o percentual incide sobre **venda de conteúdo/serviço digital de uma plataforma de tecnologia**, não sobre rendimentos de prostituição; a Performer **não exerce prostituição** na plataforma. Isso exige que **não haja** oferta/negociação de serviço presencial. **Ponto central do parecer.**

### 3.4. Arts. 218-C e 216-B — Divulgação/registro não autorizado de intimidade sexual

**Risco:** uma Performer (ou Membro) subir material de terceiro sem consentimento; Membro vazar conteúdo. **Controles:** consentimento documentado de quem aparece (contrato), KYC, denúncia prioritária, congelamento de prova, marca d'água (**hoje desligada**; ligar após self-test), política de vazamento (banimento, responsabilização). **Gap:** não há verificação automática de que a pessoa no vídeo é a verificada. *Mitigação futura:* prova de vida em publicações suspeitas; amostragem.

### 3.5. ECA arts. 240 a 241-E (e 244-A) — Material de abuso sexual infantil

**Gravidade máxima.** Produzir, armazenar, hospedar e disponibilizar material com crianças/adolescentes é crime; **o provedor** tem dever de **comunicação** e de **remoção** ao tomar ciência. **Controles existentes:** maioridade + KYC, política de tolerância zero, denúncia prioritária ("menor de idade" é *urgent*), preservação de provas, hard delete que preserva evidência de *match*. **Gaps críticos (do Gap Analysis):** detector CSAM **é MVP** (match exato contra lista local **vazia**, sem near-match/PhotoDNA, **não cobre vídeo**, fail-open). **Ação antes do primeiro upload em produção:** integrar base real (parcerias com NCMEC/IWF/SaferNet/PhotoDNA ou equivalente), cobrir vídeo (amostragem de quadros), definir **procedimento de comunicação** às autoridades e de preservação. *Observação:* **não prometer em texto público o que o sistema não faz** — os textos publicados falam em "ferramentas de detecção quando disponíveis".

### 3.6. Art. 149-A (tráfico de pessoas) e coerção

Risco indireto: uso da plataforma por terceiros que controlam a Performer. Controles: KYC, 2FA, saque PIX **somente para chave de titularidade da Performer** *(verificar)*, canal de denúncia de coerção e prioridade, detecção de **IP compartilhado entre Performers** (gap), revalidação periódica de KYC (gap).

### 3.7. Art. 234 — Escrito ou objeto obsceno

Dispositivo antigo sobre fazer/ter para comércio ou exposição "objeto obsceno". A aplicação a conteúdo adulto entre adultos, em plataforma de acesso restrito a maiores, é **discutível**; incluir no parecer.

---

## 4. Responsabilidade civil e Marco Civil da Internet

- **Remoção e notificação**: o regime de responsabilidade de provedores de aplicação por conteúdo de terceiros foi **objeto de decisão recente do STF** (verificar o alcance atual do art. 19 do MCI). Prática recomendada: **notice-and-takedown** ágil para conteúdo manifestamente ilícito (CSAM, intimidade sem consentimento, ameaça), independentemente de ordem judicial.
- **Intimidade sexual (MCI art. 21)**: notificação da pessoa retratada basta para remoção — **canal dedicado** (feito: denúncia prioritária) e prazo interno.
- **Guarda de registros (MCI art. 15)**: provedor de aplicações constituído como pessoa jurídica deve guardar **registros de acesso por 6 meses**; antes do CNPJ, avaliar a obrigação.
- **Termos**: cláusulas de limitação de responsabilidade respeitando o CDC (feito nos Termos).

---

## 5. LGPD

- **Dado sensível por contexto** (vida sexual) → consentimento específico e destacado e/ou outra base do art. 11. *(Termos/Privacidade publicados já estruturados assim.)*
- **Retenção de mensagens × direito de eliminação (art. 18):** o código **retém** as mensagens (soft-delete) para prova; falta **base legal escrita + prazo definido** (decisão jurídica).
- **Mensagens em texto puro no banco** e **sem TTL de `audit_logs`**: itens técnicos de risco.
- **Perfil do membro (opt-in vs. opt-out)**: ver `PENDENCIAS_JURIDICAS.md` §6; manter **opt-in**.
- **Transferência internacional**: provedores fora do Brasil (e-mail, vídeo, captcha, KYC); formalizar cláusulas-padrão.
- **Prospecção de Performers (CRM)**: legítimo interesse + opt-out (texto na Privacidade §11.2).
- **Encarregado (DPO)**: nomear (pode ser um sócio) e publicar canal.
- **ROPA e RIPD**: registro de operações e relatório de impacto (recomendável pelo tratamento de dados sensíveis).

---

## 6. Consumidor, tokens e pagamentos

Ver `04_ENQUADRAMENTO_REGULATORIO_TOKENS.md`.

---

## 7. Geobloqueio e extraterritorialidade

O desenho prevê bloqueio de países (padrão: `US`) por causa da legislação **FOSTA-SESTA**. **Hoje o driver está inativo (`GEO_DRIVER=none`, fail-open).** Ligar um driver real antes do lançamento e **não afirmar** em texto público que "usuários dos EUA não acessam" (é contornável por VPN).

---

## 8. Plano de ação (priorizado)

### Sem custo, já (engenharia/PO)

| # | Ação | Status |
|---|---|---|
| 1 | Publicar Termos, Contrato, Privacidade, Conteúdo/Moderação e Verificação Etária (minutas) | **feito nesta entrega** |
| 2 | Marcar o site como conteúdo adulto (meta RTA) | **feito nesta entrega** |
| 3 | Assinar MoU entre sócios (doc 01) | a fazer |
| 4 | Nomear encarregado e criar e-mail de privacidade (`LEGAL_CONTACT_EMAIL`) | a fazer |
| 5 | Ligar geobloqueio real (Cloudflare/MaxMind) | a fazer antes do go-live |
| 6 | Integrar base CSAM real e vídeo | **bloqueador de go-live** |
| 7 | Reenviar confirmação, lembrete 24h e tela admin de pendentes da lista de espera | próxima PR |
| 8 | Detecção de agenciamento Nível 1 | pós-UAT |
| 9 | Cifrar `messages.body`, TTL de `audit_logs`, KYC passar por sanitização | backlog técnico |

### Com advogado (em jan/2027, após validar demanda)

1. Revisar/ratificar o parecer de Art. 228 e tokens (e ECA Digital).
2. Revisar as minutas publicadas e fechar o MoU em contrato social + acordo de sócios + vesting.
3. Cessão de PI e CNPJ.
4. Parecer sobre arranjo de pagamento e tributação (com contador).
5. Marca (INPI): a **busca** logo; o depósito pode esperar.

### Perguntas objetivas para o advogado

1. A permissão de **troca de contato** no chat pago aumenta o risco do art. 228? O que os Termos devem dizer?
2. A retenção de mensagens para prova é compatível com a LGPD? Qual prazo?
3. A moderação reativa por denúncia basta, ou há obrigação de monitoramento proativo?
4. O desenho de **repasse** (Limen recebe, depois paga a Performer) configura **instituição de pagamento / subcredenciador**? Vale usar **split** no PSP?
5. Quais **cláusulas mínimas** de consentimento devem constar do contrato para pessoas que aparecem no conteúdo (co-performers)?
6. Como tratar **tributariamente** o recebimento antecipado e o repasse?
7. Quais são as **obrigações do provedor** quanto a CSAM (comunicação e retenção) e como formalizá-las?
