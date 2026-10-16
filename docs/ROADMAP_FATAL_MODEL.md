# Roadmap — Limen × Fatal Model (estratégia CEO/CTO)

> Origem: análise do PO (Robson) de 14/10/2026, a partir de capturas do perfil público e da home da Fatal Model. Consolidada aqui como roadmap vivo (antes vivia só em conversa). **Premissa central:** copiar a **experiência** da Fatal (verificação, galeria por rosto, stories, boost, marca d'água) **sem copiar o modelo** (contato direto, preço por hora, local, avaliação de encontro) — é essa fronteira que preserva o parecer jurídico que já temos (token = passivo; produto = conteúdo e conexão; filtro de chat barra encontro pago).

## 1. Linha vermelha — o que NUNCA copiar (Art. 228)

A Fatal vende **encontro presencial**. Cada peça abaixo é prova de intermediação:

| Recurso da Fatal | Decisão Limen |
|---|---|
| Botão WhatsApp/Telegram no perfil | **Nunca.** Contato só dentro do Limen (chat/chamada pagos) |
| "R$ 1000 (1 hora)", "atende casais" | **Nunca.** Preço é por conteúdo/minuto de vídeo |
| Localização por bairro, "com local" | **Nunca por bairro.** Cidade/estado só como afinidade |
| Avaliação do encontro (★ "entregou 110%") | **Nunca.** Só sinais seguros: "ativa este mês", gorjetas, resposta rápida |
| Preço por hora / R$/hora | **Nunca.** No máximo "chamada até X tokens/min" |
| Número exato de seguidores | **Nunca** exato — faixa (piso de anonimato) |
| Compartilhar URL da mídia | **Nunca** — vetor de vazamento; no máximo "copiar link do perfil" |
| Qualquer plano que venda **posição** em vez de **ferramenta** | **Nunca** — posição é só o Boost pontual, já validado |

## 2. O que o Limen JÁ tem (não refazer)

Boost (`spend_boost`), trilha de stories + "Agora" (live), filtro "Online agora", apresentação de voz, selo de verificada (`is_verified`, KYC criptografado), marca d'água por espectador (`WatermarkService`, hoje só em encomenda e desligada), stories com avançar/coração/reação agregada/enquete, responder story no chat pago (`feat/story-reply-to-chat`), insights, canal de transmissão, faixa de seguidores. O Limen tem **mais alavancas de descoberta que a Fatal**.

## 3. Priorização — 12 iniciativas, 3 ondas

Critério: **o que mais move o produto por hora de dev, sem tocar a linha do Art. 228.**

| # | Iniciativa | Impacto | Esforço | Risco jurídico | Depende de |
|---|---|---|---|---|---|
| 1 | Frase de destaque no card + selo "Voltou" | Alto | Baixo (2 dias) | Zero | — |
| 2 | Checklist de crescimento no painel dela | Alto | Baixo | Zero | — |
| 3 | Barra "Responder…" visível + presente no story | Médio | Baixo | Zero | — |
| 4 | Filtros "Com voz" e "chamada até X tokens/min" | Médio | Baixo | Zero | — |
| 5 | Marca de origem (`limen · @slug`) em tudo público | Médio | Baixo | Zero | — |
| 6 | `shows_face` + abas da galeria + filtro "Com rosto" | Alto | Médio (1 sprint) | Zero | — |
| 7 | Marca forense por espectador em todo desbloqueio | Alto | Médio foto / Alto vídeo | Baixo (nota nos Termos) | selftest + entrega real |
| 8 | **Rosto pago v1** (avatar discreto + blur do paywall) | **Muito alto** | Médio (1 sprint) | Baixo | 6 e 7 |
| 9 | Vídeo de verificação privado + revalidação + selos | Alto | Médio | Baixo (LGPD, storage) | — |
| 10 | **Limen Pro** (plano da performer) | Médio-alto (receita) | Médio | Médio (enquadrar como ferramenta) | 7, 8 e demanda real |
| 11 | Feed vertical de vídeo curto | Alto | Alto | Baixo (carga/CDN) | CDN |
| 12 | "Seleção Limen" editorial | Médio | Baixo | Médio (responsabilidade) | critério público |

### As três ondas

- **Onda A — rápidas e sem risco (2–3 semanas):** itens 1 a 5. Mudam o card, o story e a proteção básica sem tocar modelo nenhum.
- **Onda B — o diferencial (2 sprints):** 6 → 7 → 8, nessa ordem obrigatória (rosto pago sem marca forense é promessa vazia; marca forense sem `shows_face` não sabe o que proteger). Frase de recrutamento: **"mostre o rosto só para quem paga, e se vazar você sabe quem foi"** — o que a Fatal não copia sem mudar o modelo dela.
- **Onda C — confiança e receita (quando houver performers pedindo):** 9 e depois 10. O Pro entra quando elas pedirem mais limite; o vídeo de verificação entra antes do primeiro caso de catfish. 11 e 12 ficam para depois do go-live.

## 4. Detalhe das peças-chave

### 4.1. Rosto pago (seções 6–8) — o grande diferencial
- **Política de rosto por performer:** Público (como hoje) · Pago (rosto só em Premium/Exclusivo/FC) · Só Fã-Clube.
- **Avatar e capa discretos:** segundo slot sem rosto para todo lugar público (card do catálogo, trilha de stories, topo do perfil para quem não pagou). Ela escolhe a foto — sem detecção de rosto/IA na v1.
- **`shows_face` + política "Pago":** toda peça marcada com rosto vira paga e a prévia pública sai **borrada** — reusando o `blur_url` do paywall (servidor, irreversível). Corpo sem rosto segue público; ela continua no filtro "Com rosto".
- **"Revelar rosto" como produto:** uma peça Premium dedicada ("Meu rosto — 40 tokens"). Versão monetizada e consentida do "exclusivo" da Fatal.
- **v2 (depois de validar demanda):** blur automático da região do rosto nas versões públicas (detecção no upload, uma vez por imagem).
- **Por que vende:** no mercado adulto BR, parte enorme das performers não quer o rosto público (família, emprego). A Fatal força a escolha binária; o Limen oferece "mostre só para quem paga". É segurança para ela, produto para vender, e coerente com o DNA de privacidade do membro.

### 4.2. Marca forense por espectador (seção 3)
- **Marca de origem** (`limen · @slug`) em tudo público/grátis: dissuade raspagem, prova origem. Renderiza uma vez no upload, cacheável.
- **Marca forense por espectador** ("Fã #NNNN · dd/mm/aaaa") em todo desbloqueio pago (Premium, Exclusivo, Fã-Clube, PPV, encomenda): no momento do desbloqueio gera a cópia carimbada e serve **essa** cópia. Reusa o `WatermarkService` (hoje só encomenda) — só estende o gatilho.
- **Limite honesto:** marca visível é **dissuasão + atribuição**, não DRM. Nunca prometer "impossível vazar"; prometer **"rastreável"**. Print/gravação de tela não têm bloqueio na web (nem a Fatal tem) — argumento para um app nativo no futuro (FLAG_SECURE no Android).

### 4.3. Verificação (seção 4) — copiar a ideia, manter tudo privado
A Fatal acerta na ideia (vídeo de verificação, revalidação, selo por foto) e erra na execução (publica o vídeo, às vezes com nudez, e mostra "25 anos no documento" — vaza KYC). O Limen mantém **tudo privado**: o vídeo fica só com a moderação; o público vê só os selos.
- **Selos derivados:** "Maior de idade verificada" (nunca a idade do documento), "Mídia verificada" (moderação confere que a foto é da pessoa verificada), "Sem filtro" (declaração da performer + checagem por amostragem — nunca garantia da plataforma).
- **Vídeo de verificação:** rosto + gesto/código do dia, ~20 s, **privado** (storage criptografado como o KYC, retenção curta, acesso logado por moderador, nunca público).
- **Revalidação:** 12 meses (6 se aparecer fraude).
- **O que o membro pode saber:** "é pessoa real, maior de idade, e este conteúdo é dela" (dá para afirmar). **"Não é IA/sem edição" NÃO dá para garantir** tecnicamente — vira declaração da performer, não promessa da plataforma.

### 4.4. Limen Pro (plano da performer) — a 2ª fonte de receita
- Plano mensal pago **em tokens** (debitado do saldo que ela já ganhou; sem PSP novo, sem CPF extra), ~120 tokens/mês. `entry_type` novo `spend_performer_plan` (gasto, fora do payout, não sacável), tratamento contábil igual ao Boost.
- **O que desbloqueia:** texto livre nas mensagens de catálogo (passando pelo filtro estrito do apelido), 40 msgs grátis/dia (de 15), 10 fixados (de 3), insights 90 dias, 1 boost/mês incluso, saque sem mínimo, marca forense, modo "rosto exclusivo".
- **Nome:** "Limen Pro" (evitar "VIP"/"Luxo" — vocabulário da Fatal).
- **6 cuidados (sem maquiar):** (1) Art. 228 — o plano **nunca** mexe em posição (posição é só Boost); (2) texto livre reabre fuga de contato → passa pelo filtro estrito; (3) pay-to-win mata confiança → plano é capacidade, não palco; (4) canibalização do split → só melhorar o split se o plano trouxer mais volume do que custa; (5) timing → lançar quando elas **pedirem** mais limite (o pedido é o sinal de demanda); (6) suporte → plano pago gera expectativa de atendimento.

## 5. Ideias de descoberta baratas (Onda A)
- **Checklist de crescimento no painel dela:** "Voz ✓ · Capa ✓ · 3 stories esta semana ✗ · Ao vivo este mês ✗". Gamifica os sinais que o ranking usa.
- **Frase de destaque** (40 caracteres, via `SafeProfileText`) no card e no perfil + **selo "Voltou"** (7 dias após 30 inativa).
- **Ordenação transparente:** Mais ativas · Novas · Ao vivo · Mais seguidas (faixa). **Nunca por avaliação.**
- **"Parecidas com quem você salvou"** — recomendação por afinidade (salvos, favoritos, mundos).
- **Filtros** "Com voz" e "Com rosto" (este último significa "tem peças com rosto", mesmo pagas — não "rosto público").

## 6. Decisões de produto registradas (14/10)
- Marca d'água de origem em tudo (avatar, capa, grátis, story): gravada uma vez no upload, quase invisível, serve de prova. Fã # por espectador só no desbloqueio pago.
- "Sem edição / sem IA" **não** vira garantia da plataforma — vira declaração + amostragem + penalidade.
- "Mídia verificada" = pessoa real, maior de idade, conteúdo dela.
- Rosto pago (seção 4.1): **aprovado** como diferencial.
- A lista do "nunca" (seção 1): **aprovada**.

## 7. Pendências para o advogado (ligam com `PENDENCIAS_JURIDICAS.md`)
- **Biometria automatizada** (comparação de rosto no vídeo de verificação): é dado sensível (LGPD art. 11) — base legal, consentimento específico e destacado, RIPD, prazo de retenção; e avaliar contratar fornecedor de KYC com prova de vida (idwall/Unico/Serpro) que já carrega a conformidade. Decisão do PO: automatizar quando entrar na pauta. Até lá, moderação humana.
- **Marca d'água com o apelido do membro** gravada no conteúdo que ele desbloqueia: cláusula nos Termos (base: execução de contrato / antifraude). *(Já coberto nas minutas publicadas; confirmar na revisão.)*
- **Enquadramento do `spend_performer_plan`** (Limen Pro): mesmo tratamento do Boost. Só quando o Pro entrar na pauta.

## 8. Sequenciamento acordado com o UAT
1. Terminar o UAT atual (Fases 11–17) sem injetar feature nova no meio.
2. Construir as ondas A → B → C com o UAT fechado.
3. Retestar só as ondas (teste dirigido ao que mudou).

> Flexibilidade: os itens 1 e 2 da Onda A (frase de destaque, selo "Voltou", checklist) são pequenos e sem risco — cabem entre duas fases do UAT se sobrar janela, sem obrigação e sem mudar a estratégia.
