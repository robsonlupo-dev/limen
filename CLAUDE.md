# Limen — Guia do Projeto (leia antes de qualquer tarefa)

Plataforma premium de conteúdo adulto verificado para o mercado brasileiro.
Este arquivo é o cérebro do projeto. O Claude Code deve segui-lo em toda sessão.

## Stack
- PHP 8.5 + Laravel (versão mais recente)
- MySQL 8.4 (via Docker) — banco principal
- Redis (via Docker) — cache/filas
- Front-end: Blade + Tailwind (mudar só com aprovação do Product Owner)
- Pagamento: Asaas / PIX (a partir da Fase 5)
- Streaming: LiveKit (a partir da Fase 7)

## Princípios de arquitetura (não negociáveis)
1. **Segurança e idade primeiro.** PII sensível, KYC, 18+ dos dois lados, prevenção de conteúdo ilegal. É fundação, não feature.
2. **Saldo de tokens é derivado de um ledger append-only.** NUNCA fazer `UPDATE ... saldo = saldo + x`. Todo movimento é uma linha nova em `token_ledger`; o saldo é a soma. (Erro recorrente no projeto anterior — não repetir.)
3. **Idempotência em pagamento.** Crédito de tokens só via webhook idempotente por id de evento. Reprocessar nunca duplica saldo.
4. **PII isolada e criptografada.** CPF, documentos e dados de verificação ficam em tabela separada, criptografados em repouso, em storage privado. Nunca em log, nunca em URL.
5. **Nada de segredo no Git.** Tudo em `.env` (fora do versionamento). 
6. **Dados reais só em produção.** Dev/staging usam dados sintéticos.

## Convenções
- Migrations versionadas para TODA mudança de schema. Nunca alterar o banco à mão.
- Validação sempre via Form Requests (nunca confiar no input cru).
- Queries via Eloquent/Query Builder com bind. Nunca concatenar string em SQL.
- Auth via Laravel Sanctum (tokens com expiração/rotação).
- Dinheiro/tokens como inteiros (centavos / tokens), nunca float.
- Commits pequenos, em inglês, no imperativo ("add token ledger migration").
- 1 PR por entrega. Testes verdes antes de marcar como pronto.

## Fluxo de trabalho
- O Product Owner (Robson) abre issues no GitHub para bugs e mudanças.
- Cada fase termina com: suíte de testes verde + passo de debug + revisão de segurança.
- Antes de implementar algo sensível (cadastro, KYC, pagamento, payout), rodar o subagente de segurança.

## Modelo de tokens (resumo — detalhe na Fase 5)
- Cliente compra pacotes de tokens via PIX.
- Cliente gasta tokens (gorjeta, sessão privada).
- No gasto, a plataforma retém um split por nível do performer; o restante credita o performer.
- Tudo isso é registrado no `token_ledger` (append-only).

## Estado atual
- Fase 0: fundação do repo + ambiente (MySQL/Docker).
- Fase 1: modelo de dados + segurança de base (migrations, models, TokenService, seeder).
- Fase 2: autenticação + cadastro (Sanctum API, register/login/logout/me, email verification, password reset, role middleware, policies, audit log).
- Fase 3: compra de tokens + Asaas/PIX (cliente mockável, pagamento, webhook idempotente, reconciliação agendada).
- Próxima: Fase 4.
