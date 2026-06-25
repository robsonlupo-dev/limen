---
name: laravel-api-conventions
description: Convenções de API REST do Limen. Use ao criar rotas, controllers, requests, resources ou autenticação de API.
---

# Convenções de API — Limen

## Rotas e versão
- Tudo sob `/api/v1`. Rotas em `routes/api.php`, agrupadas por recurso.
- Nomes de rota claros (`auth.login`, `auth.me`).

## Formato
- Respostas via API Resources (`app/Http/Resources`). Nunca retornar o model cru.
- Nunca expor senha, CPF, documentos ou tokens internos em resposta.
- Erros no padrão Laravel: 422 (validação, com `errors`), 401, 403, 404, 429.
- Status HTTP corretos: 201 ao criar, 200 ao ler/atualizar, 204 quando sem corpo.

## Autenticação
- Laravel Sanctum (bearer token). Rotas protegidas com `auth:sanctum`.
- Logout revoga o token atual.
- Token nunca em log nem em URL.

## Validação e segurança
- Validação sempre por Form Request, nunca no controller.
- Throttle (`throttle:`) em login, forgot-password e resend-verification.
- Mensagens genéricas em login e forgot (não revelar se a conta existe).
- Autorização por Policy/Gate e middleware de role.

## Controllers
- Finos: validam (Form Request), chamam Service, retornam Resource.
- Lógica de negócio em Service, com teste.
