<!-- Vocabulário: "Fase N" neste doc é LEGADO (ciclo da fundação) e NÃO
     corresponde ao "Sprint N" atual. Ex.: Fase 4 = perfis/catálogo;
     Sprint 4 = chat. O ciclo de entrega vigente é "Sprint N" — ver CLAUDE.md. -->

# Fase 2 — Autenticação + Cadastro (API com Sanctum) — Limen

API-first. Constrói o cadastro e o login em cima do modelo de dados da Fase 1.
Sem tela ainda: tudo testado via JSON. O design entra numa fase posterior.

## Escopo
- Sanctum (tokens de API).
- Cadastro de consumer e de performer.
- Login, logout, "me".
- Verificação de e-mail.
- Recuperação de senha.
- Rate limiting + autorização por role + logs de auditoria.

## Padrão de rotas
Prefixo `/api/v1`. Respostas JSON via API Resources. Erros no padrão do Laravel
(422 validação com objeto `errors`; 401 não autenticado; 403 sem permissão; 429 throttle).
Nunca retornar senha nem PII sensível (CPF/documento) nas respostas.

## Endpoints

### Cadastro
`POST /api/v1/auth/register/consumer`
- campos: name, email, password, password_confirmation, birthdate, phone?, accept_terms, terms_version
- valida: 18+ (rejeita menor e data futura), aceite de termos obrigatório, senha forte (≥8, 1 maiúscula, 1 número), e-mail único
- cria: user (role=consumer, status=active, lgpd_consent_at=now, terms_version), token_wallet (saldo 0)
- envia e-mail de verificação; grava audit_log `auth.register`
- retorna 201 + UserResource + token Sanctum

`POST /api/v1/auth/register/performer`
- campos do consumer + stage_name, category
- cria: user (role=performer, **status=pending**), performer_profile (level/split provisórios da Fase 1), identity_verification (status=pending), token_wallet
- performer NÃO pode ir ao ar até verificação (Fase 4)
- audit_log `auth.register_performer`; retorna 201 + UserResource

### Sessão
`POST /api/v1/auth/login`
- campos: email, password
- **throttle** (ex.: 5 tentativas/min por email+IP)
- sucesso: emite token Sanctum, atualiza last_login_at, audit_log `auth.login`
- falha: 401 genérico ("credenciais inválidas"), conta para o throttle

`POST /api/v1/auth/logout` (auth) — revoga o token atual; audit_log `auth.logout`
`GET  /api/v1/auth/me` (auth) — UserResource (+ PerformerProfileResource se performer)

### E-mail
`POST /api/v1/auth/email/verify/resend` (auth, throttle) — reenvia verificação
`GET  /api/v1/auth/email/verify/{id}/{hash}` — verifica via URL assinada; audit_log `auth.email_verified`

### Senha
`POST /api/v1/auth/password/forgot` — email; envia link; throttle; resposta genérica (não revela se o e-mail existe)
`POST /api/v1/auth/password/reset` — token, email, password; reseta; audit_log `auth.password_reset`

## Segurança (aplicar a skill security-checklist)
- Validação por Form Request em todo endpoint.
- Sanctum com tokens; logout revoga.
- Throttle em login, forgot e resend.
- Middleware de role (`role:performer`, `role:admin`); Policies/Gates como esqueleto.
- Audit log nas ações sensíveis listadas.
- Mensagens de erro genéricas em login e forgot (não vazar existência de conta).

## Estrutura sugerida
- `app/Http/Controllers/Api/V1/Auth/*` (finos)
- `app/Http/Requests/Auth/*` (validação) — reusar o Form Request de consumer da Fase 1
- `app/Http/Resources/{UserResource,PerformerProfileResource}.php`
- `app/Services/AuthService.php` e reuso do `TokenService` para criar o wallet
- `app/Support/Audit.php` (helper para gravar audit_logs)

## Testes (Pest, feature) obrigatórios
1. cadastro consumer ok → 201, user+wallet criados, token funciona
2. cadastro menor de 18 → 422
3. cadastro sem aceite de termos → 422
4. e-mail duplicado → 422
5. senha fraca → 422
6. cadastro performer → user pending + profile + wallet criados
7. login ok → 200 + token; last_login_at preenchido
8. login senha errada → 401; repetido além do limite → 429
9. GET /me sem token → 401; com token → 200
10. middleware de role bloqueia consumer em rota de performer
11. logout revoga token → request seguinte 401
12. fluxo de reset de senha funciona

## Definição de pronto
- todos os endpoints respondendo em JSON
- subagente `security-reviewer` rodado no fluxo de auth, achados triados
- suíte de testes verde
