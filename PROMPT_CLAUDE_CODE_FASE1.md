# Briefing para o Claude Code — Fase 1

Cole o texto abaixo no Claude Code (aberto na raiz do projeto), depois revise o que ele propõe ANTES de mandar aplicar.

---

Você é o dev do projeto Limen. Leia `CLAUDE.md`, as skills em `.claude/skills/`
(`php-laravel-conventions`, `mysql-migrations`, `security-checklist`) e a
especificação `docs/fase1-modelo-dados.md`. Implemente a Fase 1 exatamente conforme a spec:

1. Crie as migrations das tabelas: users (estendendo a padrão), performer_profiles,
   identity_verifications, token_wallets, token_ledger, token_packages, payments,
   payment_events, audit_logs. Siga tipos, FKs, índices e regras de PII da spec.
2. Crie os Models Eloquent com relações e casts. PII em identity_verifications com
   cast `encrypted`. token_ledger imutável (sem updated_at).
3. Crie um Form Request de cadastro de consumer: validação 18+ (rejeita menor e data
   futura), aceite de termos obrigatório, senha forte (≥8, 1 maiúscula, 1 número).
4. Crie `app/Services/TokenService.php` com balance/credit/debit usando transação +
   lock de linha no wallet, insert no token_ledger com balance_after, e impedindo
   saldo negativo no débito.
5. Crie um Seeder com os token_packages da spec e 3 usuários sintéticos (admin,
   performer pending, consumer). Nenhum dado real.
6. Escreva testes Pest para as 5 invariantes da spec.

Regras: migrations reversíveis; dinheiro/tokens como inteiros; nada de segredo no
código; rode contra o MySQL de dev (Docker).

Quando terminar:
- rode `php artisan migrate:fresh --seed`
- rode a suíte de testes e corrija até ficar tudo verde
- me mostre um resumo do que criou e o resultado dos testes

Não faça nada além da Fase 1. Se algo na spec estiver ambíguo, pergunte antes de assumir.
