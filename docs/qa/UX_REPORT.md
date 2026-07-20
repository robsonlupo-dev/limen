<!-- Vocabulário: "Fase N" neste doc é LEGADO (ciclo da fundação) e NÃO
     corresponde ao "Sprint N" atual. Ex.: Fase 4 = perfis/catálogo;
     Sprint 4 = chat. O ciclo de entrega vigente é "Sprint N" — ver CLAUDE.md. -->

# LIMEN — UX REPORT (Operação de QA · 02/07/2026)

> Avaliação heurística do **código Vue real** (rubrica de 7 dimensões: clareza do texto,
> fluxo, estado vazio, loading, mensagens de erro, consistência visual, responsividade).
> Nota 0–10 por tela. Sinais objetivos coletados por análise estática (contagem de estados
> de loading/empty/error e classes responsivas por arquivo).

## Resumo

| Tela | Nota | Pontos fortes | Problemas | Melhoria sugerida |
|---|---|---|---|---|
| Entrada (role picker) | 8.5 | Pergunta-título forte ("Para quem você é o portal?"), expectativa de KYC declarada no card do performer, caminho de login presente | Emojis genéricos (👤/🌟) destoam do tom premium | Substituir emojis por ícones da identidade visual |
| Landing | 8.0 | Tom premium, hierarquia serif/dourado consistente | Página estática sem prova social (nº de performers verificados) | Adicionar contadores reais do catálogo |
| Login | 8.0 | Erro específico em PT-BR, estado `processing` no submit | Sem link visível para `entrada` quando o usuário não tem conta? (verificar em tela) | Garantir CTA "Criar conta" proeminente |
| Cadastro (membro/performer) | 8.5 | 2 fluxos no mesmo form via `?tipo=`, 10 pontos de exibição de erro, validação client+server | Form longo sem indicador de progresso no fluxo performer | Stepper visual para o fluxo performer |
| Esqueci minha senha | 8.0 | Fluxo completo PT-BR com feedback de envio | Não informa prazo de expiração do link | Mencionar validade do link no texto |
| Redefinir senha | 8.0 | Erros por campo, processing | — | — |
| Verificar e-mail | 7.5 | Instrução clara, reenvio e "usar outro e-mail" (logout→cadastro) | **Sem feedback visível após reenviar** (post sem toast/flash na tela) | Exibir confirmação "link reenviado" após o POST |
| Catálogo (Index) | 9.0 | Skeleton de loading real, **empty state excelente** ("O Portal ainda está abrindo suas portas"), picker de mundo em modal, paginação estilizada | Mundo atual só em texto pequeno no header | Chip de mundo mais proeminente + contagem de resultados |
| Perfil público (Show) | 7.0 | Header rico (avatar, badge, rating, contadores, valores por modo) | **CTA principal "Enviar gorjeta" é placeholder desabilitado ("Em breve")** — a API de tips existe e funciona desde a Fase 6, mas a UI não a consome. Loop de monetização quebrado no front | **P0 de produto:** ligar o modal ao `POST /api/v1/tips` (saldo, erros, sucesso) |
| Painel performer (Dashboard) | 7.5 | Cards de saldo/ganhos/seguidores/KYC, empty state de gorjetas, botão live desabilitado com tooltip explicativo | "Ir ao vivo" é CTA morto (streaming é fase futura) no topo da página | Trocar por CTA útil (completar perfil / ver payouts) até o streaming existir |
| Carteira (Wallet) | 9.0 | Loading por pacote, erro de CPF específico + erro geral, toast de sucesso, labels PT-BR para cada entry_type do ledger | — | Mostrar bônus do pacote com destaque maior |
| Histórico da carteira | 8.0 | Empty states presentes, labels por tipo | Sem loading state na paginação | Reusar o padrão de skeleton do catálogo |
| Onboarding performer | 8.0 | Upload com validação e erros por campo | Sem preview do avatar após upload? (verificar em tela) | Preview imediato pós-upload |
| Payouts (Index) | 8.5 | 4 empty states, 3 loadings, erros específicos; regras de valor mínimo/máximo comunicadas | — | — |
| Payouts (History) | 8.0 | Empty states, status coloridos | Sem filtro por período | Filtro simples por mês |

**Média geral: 8.1 / 10**

## Destaques positivos (padrões a manter)
- Design system coeso: serif no display, dourado/preto, `border-frame`/`bg-surface` em todo lugar.
- Empty states com voz própria da marca (ex.: catálogo) — não são telas mortas.
- Mensagens de erro em PT-BR específicas (login, CPF na carteira).
- Estados de loading reais (skeleton no catálogo, spinner por pacote na carteira).

## Problemas priorizados
1. **[P0 produto] Gorjeta desligada na UI** (`Catalog/Show.vue`): botão "Em breve" desabilitado
   com API pronta — bloqueia o único fluxo de gasto do membro. É o gap nº 1 de monetização.
2. **[P2] Reenvio de verificação sem feedback** (`Auth/VerifyEmail.vue`): usuário não sabe se o
   e-mail foi reenviado.
3. **[P2] CTA morto "Ir ao vivo"** no dashboard do performer (streaming inexistente).
4. **[P3] Emojis genéricos** na Entrada e no picker de mundo destoam do tom premium.

> Ressalva de método: avaliação por leitura de código + sinais estáticos, sem navegação
> visual em browser (VM sem display). A validação manual tela a tela do PO segue necessária
> para questões puramente visuais (espaçamento, contraste real, quebras responsivas).
