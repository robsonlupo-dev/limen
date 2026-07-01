# Relatório de Deploy — Fase 12

**Status:** ✅ Artefatos de deploy prontos. Aguarda ações de infra de Robson.

## Criado
- `.github/workflows/deploy.yml` — job `test` (PHP 8.3 + sqlite, `npm ci`, build,
  `php artisan test`) e job `deploy` (SSH para Hetzner, só em push na `main`).
- `deploy.sh` — deploy manual no servidor (fallback do CI/CD).
- `docs/backup.sh` — template de backup (mysqldump + storage privado, retenção 14 dias).
- `.env.production.example` — template com placeholders, sem segredos.
- Middleware `SecurityHeaders` (também requisito da Etapa 2).

## Decisões fixadas
- Hosting: Hetzner Cloud CX22 (Nuremberg) · KYC: Unico · SSL: Let's Encrypt · CI/CD: GitHub Actions.

## GitHub Secrets necessários
- `HETZNER_HOST` — IP público do servidor.
- `HETZNER_SSH_KEY` — chave SSH privada do usuário `deploy`.

## Ações manuais de Robson
Ver `.claude/handoff/go-live-checklist.md` (seções Infraestrutura e Contas externas).
