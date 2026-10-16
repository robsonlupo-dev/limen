# Runbook — Entregabilidade de e-mail (SPF / DKIM / DMARC)

> Objetivo: garantir que os e-mails que a Limen envia (confirmação da lista, lembrete de 24h, drip, OTP de login) **cheguem à caixa de entrada**, não ao spam. Remetente: `noreply@thelimen.com.br` via **Resend** (`MAIL_MAILER=resend`).

> Importante não confundir: `LEGAL_CONTACT_EMAIL` (o endereço que aparece nos textos jurídicos para o usuário **escrever**) não tem nada a ver com entregabilidade. Quem decide spam é o **domínio remetente** (`thelimen.com.br`) + os registros abaixo.

## 1. Conferir no painel da Resend

1. Resend → **Domains** → `thelimen.com.br` deve estar **Verified** (verde). Se estiver só o `onboarding@resend.dev`, os envios de produção saem de um domínio compartilhado e caem em spam — verificar o domínio próprio é o passo nº 1.
2. A Resend lista os registros DNS que ela exige. Normalmente:
   - **SPF / MX de envio**: um registro TXT/MX em um subdomínio de envio (ex.: `send.thelimen.com.br`).
   - **DKIM**: um TXT (ex.: `resend._domainkey.thelimen.com.br`) com a chave pública.
3. Todos precisam estar **Verified**. Se algum estiver "Pending", o DNS ainda não propagou ou o registro está errado.

## 2. Conferir pelo terminal (qualquer máquina)

```bash
# SPF do domínio (deve existir e, idealmente, incluir a Resend)
dig +short TXT thelimen.com.br | grep -i spf
# ex. esperado: "v=spf1 include:amazonses.com ~all"  (a Resend usa SES por baixo)

# DKIM da Resend (o nome exato vem do painel; normalmente resend._domainkey)
dig +short TXT resend._domainkey.thelimen.com.br

# DMARC (ver seção 3)
dig +short TXT _dmarc.thelimen.com.br
```

Se o `dig` do DKIM vier vazio, o registro não existe/propagou — copie exatamente o valor do painel da Resend para o DNS (no provedor do domínio).

## 3. DMARC — criar se não existir

O DMARC diz aos provedores o que fazer quando SPF/DKIM falham e dá relatórios. Começar em `p=none` (só monitora, não derruba nada) e endurecer depois.

Registro TXT em `_dmarc.thelimen.com.br`:

```
v=DMARC1; p=none; rua=mailto:dmarc@thelimen.com.br; fo=1; adkim=s; aspf=s
```

- `p=none`: fase de observação (recomendado no começo). Depois de algumas semanas sem falso positivo, subir para `p=quarantine` e, por fim, `p=reject`.
- `rua`: e-mail que recebe os relatórios agregados. Pode ser um Gmail (via encaminhamento) — não precisa ser do domínio.
- Não existe DMARC sem SPF **ou** DKIM alinhados; por isso a seção 1 vem antes.

## 4. Checklist rápido (marcar antes de divulgar a lista)

- [ ] Domínio `thelimen.com.br` **Verified** na Resend.
- [ ] DKIM (`resend._domainkey...`) resolvendo no `dig`.
- [ ] SPF do domínio inclui o caminho de envio da Resend.
- [ ] DMARC em `_dmarc.thelimen.com.br` (ao menos `p=none`).
- [ ] `MAIL_FROM_ADDRESS=noreply@thelimen.com.br` e `MAIL_FROM_NAME` definidos no `.env`.
- [ ] Teste real: inscrever um e-mail Gmail e um Outlook na lista e confirmar que o e-mail cai na **entrada** (não em spam/promoções). Se cair, marcar "não é spam" e reavaliar o alinhamento.
- [ ] Rodar `php artisan waitlist:send-nurture` manualmente uma vez e conferir que o lembrete de 24h sai para uma inscrição de teste não confirmada.

## 5. Boas práticas de conteúdo (reduzem spam)

- Remetente com nome ("Limen") + `noreply@thelimen.com.br`.
- Link de descadastro presente (já existe: `waitlist.unsubscribe`).
- Evitar palavras de spam no assunto e excesso de links/imagens.
- Não disparar em massa de uma vez (o `nurture_max_per_run` já limita por hora).
- Aquecer o domínio: volume crescente nos primeiros dias, não um blast.

## 6. Onde isso aparece no código

- Envio: `config/mail.php` (`MAIL_MAILER=resend`), `config/services.php` (`resend.key`).
- E-mails: `app/Mail/WaitlistConfirmationMail.php` (confirmação + lembrete), `WaitlistNurtureMail.php`, OTP de login.
- Lembrete de 24h: `WaitlistNurtureService::dispatchConfirmationReminders()`, config `waitlist.confirm_reminder`, comando `waitlist:send-nurture` (horário).
- Reenviar confirmação: `waitlist.resend` (formulário público).
