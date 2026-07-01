# Relatório i18n — Fase 12

**Status:** ✅ Concluído — zero strings em inglês visíveis ao usuário.

## Feito
- Criados `lang/pt_BR/auth.php`, `pagination.php`, `passwords.php`, `validation.php`
  (validation completo, incluindo bloco `attributes` com CPF, chave PIX, senha etc.).
- `config/app.php`: `locale`, `fallback_locale`, `faker_locale` → `pt_BR`.
- `.env` de dev alinhado (`APP_LOCALE=pt_BR`).
- Páginas de erro PT-BR no design Limen: `resources/views/errors/{404,403,500,419}.blade.php`
  + layout compartilhado `errors/layout.blade.php` (self-contained, não depende do build Vite).

## Auditoria do frontend
- Varredura em `resources/js/Pages/` e `resources/js/Components/`: todos os textos
  visíveis (títulos `<Head>`, labels, placeholders, botões, estados de erro/vazio)
  já estavam em PT-BR. Os matches do grep eram identificadores de código
  (props `required`, classes `text-success/text-danger`, nomes de variáveis).

## Bug reportado (senha errada → inglês)
- Corrigido. `validation.php` tem `confirmed` e `password` em PT-BR.
- Verificado via tinker: `A confirmação do campo senha não coincide.`

**Critério atingido:** zero strings em inglês detectadas.
