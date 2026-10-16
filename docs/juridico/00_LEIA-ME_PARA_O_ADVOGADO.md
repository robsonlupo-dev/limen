# Pacote jurídico pré-lançamento da Limen — leia primeiro

> **Para quem é:** o advogado que vier a revisar o material, e os sócios.
> **O que é:** material de trabalho redigido pela engenharia/PO (com apoio de IA) para **reduzir o custo de contratar** — o advogado **revisa e adapta**, em vez de criar do zero. **Nada aqui é parecer jurídico.**

## O que há no pacote

| # | Documento | Onde está | Publicado no site? |
|---|---|---|---|
| 1 | Termos de Uso (Membros) | `resources/legal/termos-de-uso.md` → `/termos` | **Sim** (minuta) |
| 2 | Contrato de Performer | `resources/legal/contrato-de-performance.md` → `/contrato-de-performance` | **Sim** (minuta) |
| 3 | Política de Privacidade (LGPD) | `resources/legal/politica-de-privacidade.md` → `/privacidade` | **Sim** (minuta) |
| 4 | Política de Conteúdo Proibido e Moderação | `resources/legal/politica-de-conteudo.md` → `/politica-de-conteudo` | **Sim** (minuta) |
| 5 | Verificação Etária e Proteção de Menores | `resources/legal/verificacao-etaria.md` → `/verificacao-etaria` | **Sim** (minuta) |
| 6 | MoU / Pré-acordo de sócios com vesting | `docs/juridico/01_...` | Não (interno) |
| 7 | Cessão de propriedade intelectual | `docs/juridico/02_...` | Não (interno) |
| 8 | Análise jurídica do modelo e risco penal | `docs/juridico/03_...` | Não (interno, confidencial) |
| 9 | Enquadramento regulatório dos tokens | `docs/juridico/04_...` | Não (interno, confidencial) |

Os textos publicados mostram uma **faixa de aviso** de que se trata de **versão pré-lançamento sujeita a revisão** (controlada por `LEGAL_DRAFT`). Quando o advogado revisar, basta atualizar o `.md` correspondente e desligar o aviso (`LEGAL_DRAFT=false`).

## Como o aceite funciona (importante para o advogado)

- O aceite da Performer é gravado em `document_acceptances` (**append-only**): usuário, tipo, **versão**, data/hora e *hash* de IP/dispositivo.
- A versão vigente de cada documento fica em `config/documents.php` (por variável de ambiente). **Mudar a versão obriga re-aceite** das Performers: não mude por erro de digitação.
- Para o Membro, o cadastro exige marcar "aceito os termos" e o consentimento LGPD (`accept_terms`, `lgpd_consent`); **ainda não há registro de versão do aceite do Membro** — recomendável (backlog técnico).

## Decisões que precisam do advogado (por documento)

**Termos de Uso (Membros)**
- Cláusula de natureza do token (ver doc 04) e **política de reembolso**; saldo em encerramento voluntário e em banimento.
- Limitação de responsabilidade (item 10.3) frente ao CDC.
- Foro (foi adotado o domicílio do consumidor).
- Texto sobre **troca de contato** e responsabilidade por contatos fora da plataforma (art. 228).
- Licença de uso do conteúdo e responsabilidade por vazamento.

**Contrato de Performer**
- Qualificação da relação (autonomia) e riscos de vínculo/consumo.
- Percentuais e **aviso prévio de 30 dias** para alterações (compromisso adotado na minuta — confirmar se aceitável).
- Indenização (11.2) e responsabilidade da Limen (11.4).
- Obrigações tributárias da Performer e retenções da Limen.
- Cláusula 5.2 (licença) e 5.3 (uso promocional): alcance.
- Consentimento de **terceiros que aparecem no conteúdo**: modelo de termo.
- Rescisão sem justa causa com 30 dias.

**Política de Privacidade**
- Bases legais por finalidade (tabela do item 4), em especial o **consentimento** para dados sensíveis.
- **Prazos de retenção**, especialmente de **mensagens** e **provas**; nomear **encarregado** e canal.
- Transferência internacional (cláusulas).
- Prospecção de Performers por legítimo interesse (11.2).

**Política de Conteúdo e Moderação**
- Lista de proibições (por exemplo, a menção a **IA** e a regra de **encenação de violência**).
- Prazos e procedimento de **notice-and-takedown** e de **comunicação a autoridades**.

**Verificação Etária**
- Adequação às regras da **ECA Digital (Lei 15.211/2025)** e a regulamentação da ANPD vigente; o que é "mecanismo confiável" de verificação.

## Divergências e pendências que o advogado vai encontrar

1. **Divisão societária:** os documentos internos usam 66/34; o parecer externo usa 66/33 (soma 99%). Decidir.
2. **Fatos técnicos que os textos não prometem, de propósito:** a lista de comparação de **CSAM** está vazia, o detector não cobre vídeo, o **geobloqueio** está inativo, a idade é autodeclarada + CPF estrutural, e o KYC é feito por provedor terceirizado (pendente confirmar o workflow de liveness/face-match). Isso consta de `docs/LEGAL_GAP_ANALYSIS.md`.
3. **Marca d'água** em entregas de encomenda: construída, **desligada**.
4. **Processador de pagamentos:** em definição (o antigo vetou o segmento adulto); os textos falam de "provedores de pagamento".
5. **Razão social e CNPJ:** pendentes; os textos dizem "operada por seus fundadores até a constituição".
6. **E-mail de contato/privacidade:** configurar `LEGAL_CONTACT_EMAIL`.
7. **Sócio com vínculo empregatício:** revisar o contrato de trabalho (exclusividade/PI).
8. **Projeto anterior (LiveCamBR):** definir titularidade do que foi reaproveitado.

## O que NÃO deve ser feito com estes textos

- Não afirmar publicamente que há "contrato aceito" ou "termos validados por advogado" antes da revisão.
- Não prometer em texto público o que o sistema não faz (CSAM, geobloqueio, verificação por base oficial).
- Não trocar a versão por correção de digitação.
