# Estado do Deploy — Limen

Atualizado: Fase 12 (produção).

## Decisões fixadas
- Hosting: Hetzner Cloud CX22 (Nuremberg)
- KYC produção: Unico
- SSL: Let's Encrypt (Certbot)
- CI/CD: GitHub Actions (`.github/workflows/deploy.yml`)
- Domínio: limen.com.br (+ www)

## Pronto no código (Fase 12)
- i18n PT-BR completo (`lang/pt_BR/*`), locale `pt_BR` em `config/app.php`.
- Páginas de erro 404/403/500/419 no design Limen.
- Middleware `SecurityHeaders` + `config/cors.php` restrito.
- 161/161 testes verdes.
- Pipeline CI/CD, `deploy.sh`, `docs/backup.sh`, `.env.production.example`.

## Pendente (infra/contas — Robson)
Ver `.claude/handoff/go-live-checklist.md`.

## Notas de ambiente
- Máquina de dev sem `pdo_sqlite`; rodar testes via banco `limen_test` (MySQL Docker)
  ou instalar `php8.4-sqlite3`. CI instala `pdo_sqlite`.
