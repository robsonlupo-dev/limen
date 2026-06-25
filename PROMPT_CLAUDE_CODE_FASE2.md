# Briefing para o Claude Code — Fase 2

Cole no Claude Code (aberto na raiz do projeto). Reinicie a sessão antes, para o novo
subagente `security-reviewer` ser carregado. Revise o que ele propõe ANTES de aplicar.

---

Você é o dev do projeto Limen. Leia `CLAUDE.md`, as skills em `.claude/skills/`
(em especial `laravel-api-conventions` e `security-checklist`) e a especificação
`docs/fase2-auth-api.md`. Implemente a Fase 2 exatamente conforme a spec:

1. Instale e configure o Laravel Sanctum (tokens de API).
2. Crie as rotas em `routes/api.php` sob `/api/v1` e os controllers finos em
   `app/Http/Controllers/Api/V1/Auth/`.
3. Form Requests para consumer (reuse o da Fase 1) e performer, com validação 18+,
   aceite de termos e senha forte.
4. Endpoints: register/consumer, register/performer, login (com throttle), logout,
   me, verificação de e-mail (resend + verify assinado), forgot/reset de senha.
   Login e forgot com mensagens genéricas.
5. API Resources (UserResource, PerformerProfileResource) — nunca expor senha/PII.
6. Middleware de role + esqueleto de Policies. Helper de audit_log nas ações sensíveis.
7. Ao criar usuário, crie o token_wallet (reuse o TokenService). Performer entra pending.
8. Escreva os 12 testes de feature da spec.

Regras: validação por Form Request; nada de segredo no código; PII fora de respostas e logs.

Quando terminar de implementar:
- invoque o subagente `security-reviewer` sobre o fluxo de cadastro+login e me traga os achados
- rode os testes e corrija até ficar tudo verde
- me mostre um resumo do que criou, o resultado dos testes e os achados de segurança

Não faça nada além da Fase 2. Em caso de ambiguidade, pergunte antes de assumir.
