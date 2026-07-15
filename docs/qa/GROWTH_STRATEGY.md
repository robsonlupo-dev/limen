# LIMEN — GROWTH STRATEGY (Operação de QA · 02/07/2026)

> Análise CMO + Head of Growth + Head of Product + UX Lead + CRO, baseada no fluxo real
> observado no código (Fases 1–12) e nos achados do UX_REPORT.md. Referências de mercado
> (inspiração, não cópia): meupatrocinio.com.br, cameraprive.com, chaturbate.com.

## Diagnóstico do funil atual

```
Landing → Entrada (membro/performer) → Cadastro → Verificar e-mail → Catálogo
   → Perfil do performer → [GORJETA: BLOQUEADA NA UI] ✗
   → Carteira → Compra PIX ✓ (funcional e bem resolvida)
```

**O maior vazamento não é aquisição — é ativação de gasto.** O membro consegue depositar
(PIX ok) mas não consegue gastar: a gorjeta, único mecanismo de spend implementado, está
desligada no front. Todo investimento em tráfego morre nesse degrau.

## As 50 melhorias priorizadas por ROI (impacto × facilidade)

| # | Melhoria | Área | Impacto | Esforço | ROI |
|---|----------|------|---------|---------|-----|
| 1 | **Ligar a gorjeta na UI** (modal → POST /api/v1/tips, saldo, erros, sucesso) | Monetização | Altíssimo | Baixo | ★★★★★ |
| 2 | CTA "Comprar tokens" no modal de gorjeta quando saldo insuficiente | Monetização | Alto | Baixo | ★★★★★ |
| 3 | Toast global de sucesso/erro (reuso do padrão da carteira) | UX | Alto | Baixo | ★★★★★ |
| 4 | Feedback visível pós-reenvio de verificação de e-mail | Ativação | Médio | Baixo | ★★★★☆ |
| 5 | Saldo de tokens visível no header do AppLayout (sempre) | Monetização | Alto | Baixo | ★★★★★ |
| 6 | Bônus do pacote em destaque ("+20% grátis") nos cards da carteira | Conversão | Alto | Baixo | ★★★★☆ |
| 7 | Prova social na landing (nº de performers verificados, países, uptime) | Aquisição | Alto | Baixo | ★★★★☆ |
| 8 | Chip do mundo atual clicável no header do catálogo | UX | Médio | Baixo | ★★★★☆ |
| 9 | Contagem de resultados no catálogo ("34 performers em Mulheres") | UX | Médio | Baixo | ★★★★☆ |
| 10 | Trocar CTA morto "Ir ao vivo" por "Complete seu perfil" (dashboard performer) | Ativação perf. | Médio | Baixo | ★★★★☆ |
| 11 | Onboarding pós-cadastro do membro: escolher mundo em 1 tela (hoje default) | Retenção | Alto | Médio | ★★★★☆ |
| 12 | E-mail de boas-vindas com 3 performers do mundo preferido | Retenção | Alto | Médio | ★★★★☆ |
| 13 | Barra de progresso de perfil do performer (foto+bio+valores = 100%) | Ativação perf. | Alto | Médio | ★★★★☆ |
| 14 | Gorjetas rápidas pré-definidas (25/50/100) com 1 clique no perfil | Monetização | Alto | Baixo | ★★★★★ |
| 15 | Mensagem de agradecimento automática pós-gorjeta (nome do performer) | Retenção | Médio | Baixo | ★★★★☆ |
| 16 | Ordenar catálogo por "ao vivo agora" primeiro | Engajamento | Médio | Baixo | ★★★★☆ |
| 17 | Filtro persistente por sessão (lembrar últimos filtros) | UX | Médio | Baixo | ★★★☆☆ |
| 18 | Selo "Novo" para performers com <7 dias | Descoberta | Médio | Baixo | ★★★☆☆ |
| 19 | Página "como funciona" para performers (split por nível transparente) | Aquisição perf. | Alto | Médio | ★★★★☆ |
| 20 | Calculadora de ganhos na landing do performer (X tips/dia → R$/mês) | Aquisição perf. | Alto | Médio | ★★★★☆ |
| 21 | Progressão de nível visível no dashboard (iniciante→estrela: falta Y) | Retenção perf. | Alto | Médio | ★★★★☆ |
| 22 | Notificação por e-mail ao performer a cada gorjeta recebida | Retenção perf. | Alto | Médio | ★★★★☆ |
| 23 | Resumo semanal por e-mail (ganhos, novos seguidores) para performers | Retenção perf. | Médio | Médio | ★★★☆☆ |
| 24 | Histórico da carteira com saldo corrente por linha (extrato bancário) | Confiança | Médio | Baixo | ★★★☆☆ |
| 25 | Follow com feedback otimista (coração anima na hora) | UX | Médio | Baixo | ★★★☆☆ |
| 26 | Feed "novidades de quem você segue" (quando feed existir) | Retenção | Alto | Alto | ★★★☆☆ |
| 27 | Primeira compra com bônus dobrado (oferta única marcada no user) | Conversão | Alto | Médio | ★★★★☆ |
| 28 | Pacote "mais popular" destacado (ancoragem de preço) | Conversão | Alto | Baixo | ★★★★★ |
| 29 | PIX copia-e-cola com botão "copiar" + countdown de expiração | Conversão | Médio | Baixo | ★★★★☆ |
| 30 | Reenvio automático de cobrança expirada ("gerar novo PIX") | Conversão | Médio | Baixo | ★★★★☆ |
| 31 | Empty state do catálogo com CTA "explorar outro mundo" | Descoberta | Médio | Baixo | ★★★☆☆ |
| 32 | Breadcrumb/voltar no perfil do performer | UX | Baixo | Baixo | ★★★☆☆ |
| 33 | Skeleton no histórico da carteira e payouts | UX | Baixo | Baixo | ★★★☆☆ |
| 34 | Substituir emojis por ícones da identidade (entrada, mundos) | Marca | Médio | Médio | ★★★☆☆ |
| 35 | Dark premium consistente em e-mails transacionais (hoje texto) | Marca | Médio | Médio | ★★★☆☆ |
| 36 | Meta tags OG/preview de link (compartilhamento discreto) | Aquisição | Médio | Baixo | ★★★☆☆ |
| 37 | Página de status de KYC com passos visuais (enviado→análise→ok) | Ativação perf. | Médio | Médio | ★★★☆☆ |
| 38 | Motivo da rejeição de KYC visível + botão reenviar em destaque | Ativação perf. | Alto | Baixo | ★★★★☆ |
| 39 | Lembrete de e-mail não verificado após 24h (job agendado) | Ativação | Médio | Médio | ★★★☆☆ |
| 40 | Favicon/PWA manifest (ícone na home screen, discreto) | Retenção | Baixo | Baixo | ★★★☆☆ |
| 41 | Rate público/privado/câmera com tooltip explicando cada modo | Educação | Médio | Baixo | ★★★☆☆ |
| 42 | "Top da semana" por mundo (ranking leve, sem expor números) | Engajamento | Médio | Alto | ★★☆☆☆ |
| 43 | Badge de streak de login do performer (dias seguidos ativo) | Retenção perf. | Médio | Médio | ★★★☆☆ |
| 44 | Payout com previsão de chegada ("até 1 dia útil via PIX") | Confiança perf. | Médio | Baixo | ★★★★☆ |
| 45 | Extrato de payout em PDF/CSV (contabilidade do performer) | Confiança perf. | Médio | Médio | ★★★☆☆ |
| 46 | Convite de performer por performer (referral com bônus de split) | Aquisição perf. | Alto | Alto | ★★★☆☆ |
| 47 | Programa "primeiros 100" com selo fundador | Aquisição | Médio | Baixo | ★★★☆☆ |
| 48 | A/B da entrada: 2 cards vs seletor com preview do catálogo | Conversão | Médio | Alto | ★★☆☆☆ |
| 49 | Captura de e-mail na landing antes do age gate (lead nurture) | Aquisição | Médio | Médio | ★★★☆☆ |
| 50 | Analytics de funil (Plausible/Umami self-hosted, LGPD-friendly) | Dados | Alto | Médio | ★★★★☆ |

## 20 Quick wins (< 1 dia de dev cada)
Itens 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 14, 16, 18, 24, 25, 28, 29, 30, 32, 38 da tabela acima.

## 10 mudanças que aumentam receita (hipótese de lift)
| Mudança | Hipótese |
|---|---|
| Ligar gorjeta na UI (#1) | de ~0% para a taxa natural de spend — lift infinito; é o gate |
| Quick tips 25/50/100 (#14) | +30–50% no nº de gorjetas (fricção → 1 clique) |
| CTA comprar no modal sem saldo (#2) | +15–25% conversão de recarga |
| Pacote "mais popular" (#28) | +10–20% no ticket médio (ancoragem) |
| Bônus em destaque (#6) | +10–15% na escolha de pacotes maiores |
| Primeira compra bônus 2× (#27) | +20–30% na conversão 1ª compra |
| Saldo sempre visível (#5) | +5–10% frequência de recarga (consciência de saldo) |
| PIX copiar + countdown (#29) | −10–20% abandono no checkout |
| Regenerar PIX expirado (#30) | recupera 5–10% de cobranças perdidas |
| Calculadora de ganhos performer (#20) | +oferta (mais performers → mais gasto) |

## 10 mudanças que aumentam retenção (métrica-alvo)
| Mudança | Métrica-alvo |
|---|---|
| Onboarding de mundo (#11) | D1 retention de membro |
| E-mail boas-vindas com performers (#12) | D1→D7 return rate |
| Agradecimento pós-gorjeta (#15) | repeat-tip rate 7d |
| Notificação de gorjeta ao performer (#22) | D7 retention de performer |
| Resumo semanal (#23) | WAU de performers |
| Progressão de nível (#21) | ganho médio/performer/mês |
| "Ao vivo agora" primeiro (#16) | sessões com visita a perfil |
| Streak de login (#43) | dias ativos/semana do performer |
| Lembrete de verificação 24h (#39) | taxa de ativação de cadastro |
| Feed de seguidos (#26, pós-MVP) | sessões/semana de membro |

## Sequência recomendada (pré-lançamento)
1. **Semana 1 — destravar o gasto:** #1, #2, #5, #14, #28 (o funil passa a fechar).
2. **Semana 2 — confiança e conversão:** #3, #4, #6, #29, #30, #38, #44.
3. **Semana 3 — oferta (lado performer):** #10, #13, #19, #20, #21, #22.
4. **Pós-lançamento:** medir com #50 antes de otimizar o resto.

> Nota de honestidade: percentuais são hipóteses de mercado para priorização, não previsões.
> Instrumentar o funil (#50) antes de tratar qualquer número como verdade.
