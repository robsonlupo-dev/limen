# PCI DSS — SAQ-D (Limen)

Escopo: fluxo de pagamento por cartão do Limen via Asaas. Este documento resume
por que estamos em SAQ-D, o que já está coberto e o que falta antes do go-live.

## Por que SAQ-D

O Asaas **não oferece tokenização client-side** (não há um widget/iframe que
capture o cartão direto no browser e devolva um token sem o PAN passar pelo nosso
servidor). Como o dado de cartão trafega pela nossa aplicação — ainda que nunca
seja persistido — não nos qualificamos para os SAQs mais leves (A / A-EP). O
questionário aplicável é o **SAQ-D (merchant)**, o mais completo.

## O que já está coberto

- **PAN nunca persistido.** O número do cartão, validade e CVV são repassados ao
  Asaas na criação da assinatura e **não são gravados** em banco, log ou disco. O
  que guardamos é apenas o `creditCardToken` devolvido pelo Asaas.
- **`card_token` cifrado em repouso.** O token de cartão é armazenado cifrado
  (APP_KEY) — não é o PAN, mas é tratado como dado sensível.
- **HTTPS ponta a ponta.** Todo tráfego (browser ↔ app ↔ Asaas) sobre TLS.
- **Trilha de auditoria.** `card_token.stored` (na criação da assinatura) e
  `card_token.purged` (no cancelamento, quando o token é apagado) registram o
  ciclo de vida do token em `audit_logs`.
- **PII/cartão fora de log e de flash.** `cpf`, `cpfCnpj`, `card_number`,
  `card_cvv`, `card_holder` estão em `dontFlash`; nada disso volta para sessão ou
  log num erro de validação.
- **IP allowlist disponível para o webhook Asaas.** Middleware
  `VerifyAsaasWebhookIp` (`asaas.webhook_ip`) valida o IP de origem contra a lista
  oficial de IPs do Asaas. Desligado por padrão; ligar em produção (ver abaixo).
- **Acesso ao servidor por chave SSH.** Deploy por chave, sem senha; nenhum
  segredo no Git (tudo em `.env`).

## O que falta antes do go-live

- [ ] **Ligar a allowlist de IP em produção:** setar
  `ASAAS_WEBHOOK_IP_ALLOWLIST=true` no `.env` de prod e conferir a lista oficial
  (`ASAAS_WEBHOOK_ALLOWED_IPS`) contra https://docs.asaas.com/docs/official-asaas-ips.
  Manter `false` em sandbox/staging (IPs fora do conjunto de produção).
- [ ] **Completar o SAQ-D self-assessment questionnaire** (formulário oficial do
  PCI SSC) e mantê-lo arquivado. Obrigatório **antes de 20 mil transações/ano**;
  acima desse volume a bandeira pode exigir validação por QSA/ASV.
- [ ] Confirmar varredura de vulnerabilidade (`composer audit` já roda no CI como
  passo informativo — endurecer para falha dura após triagem das advisories).

## Procedimento de rotação de APP_KEY

O `card_token` é cifrado com a `APP_KEY`. Rotacionar a chave **sem plano quebra a
decodificação de todos os tokens de cartão** (assinaturas ativas param de renovar).
Nunca rotacionar sem plano de rollback.

1. **Backup.** Fazer dump dos `card_token` cifrados (e da `APP_KEY` atual) antes
   de qualquer troca. Guardar o backup em local seguro.
2. **Re-cifrar antes de trocar.** Com a app ainda usando a chave antiga,
   decifrar cada `card_token` e re-cifrá-lo com a **nova** chave (script de
   migração dedicado), gravando o resultado. Só então promover a nova `APP_KEY`.
3. **Nunca rotacionar sem rollback.** Manter a chave antiga acessível até
   confirmar que todas as assinaturas renovam com a nova chave. Em caso de falha,
   restaurar `APP_KEY` antiga + backup dos tokens.

> Nota: rotacionar `APP_KEY` também afeta os documentos de KYC cifrados no disco
> `kyc` (mesma dependência de chave). Tratar as duas superfícies no mesmo plano.
