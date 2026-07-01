# Skill: performer-payouts

## Propósito
Regras e padrões para o sistema de saque (payout) de performers no Limen.
O performer converte tokens ganhos em BRL e recebe via PIX.

## Modelo de conversão
- Taxa de conversão: tokens × R$0,099 × split_pct do performer
- split_pct já está em `performer_profiles.split_pct` (ex: 0.70 = 70%)
- Valor mínimo de saque: 500 tokens (≈ R$34,65 a 70%)
- Valor máximo por saque: 50.000 tokens por solicitação
- Saque só permitido se KYC status = active

## Tabela: payouts
```sql
CREATE TABLE payouts (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id     BIGINT UNSIGNED NOT NULL,
    tokens           INT UNSIGNED NOT NULL,         -- tokens debitados
    amount_brl       DECIMAL(10,2) NOT NULL,        -- valor em BRL calculado pelo servidor
    pix_key          VARCHAR(255) NOT NULL,         -- chave PIX do performer
    pix_key_type     ENUM('cpf','email','phone','random') NOT NULL,
    status           ENUM('pending','processing','paid','failed','cancelled') DEFAULT 'pending',
    asaas_transfer_id VARCHAR(255) NULL,            -- ID da transferência no Asaas
    failure_reason   TEXT NULL,
    requested_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at     TIMESTAMP NULL,
    created_at       TIMESTAMP NULL,
    updated_at       TIMESTAMP NULL,
    INDEX idx_performer_status (performer_id, status),
    FOREIGN KEY (performer_id) REFERENCES users(id)
);
```

## Fluxo de saque

### 1. Performer solicita saque (UI)
- Informa: quantidade de tokens e chave PIX
- Frontend calcula preview do valor em BRL (só visual)
- POST /performer/payouts → PayoutController@store

### 2. PayoutController@store
```
a) Gate: performer-active (role=performer && status=active)
b) Validar KYC: is_verified = true
c) Validar tokens: mínimo 500, máximo 50.000
d) Valor BRL = SEMPRE calculado no servidor: tokens × 0.099 × split_pct
e) Verificar saldo: TokenService::debit() com lock de linha
f) Criar registro em payouts (status=pending)
g) Criar transferência PIX no Asaas via AsaasService::createTransfer()
h) Atualizar payout com asaas_transfer_id e status=processing
i) Registrar em audit_logs
```

### 3. Webhook Asaas (transferências)
- `TRANSFER_PAID` → status=paid, processed_at=now()
- `TRANSFER_FAILED` → status=failed, failure_reason, ESTORNAR tokens ao performer
- Webhook idempotente por event.id em payment_events

### 4. Estorno de tokens (falha)
- NUNCA delete o payout — marcar como failed
- Criar linha em token_ledger tipo `payout_reversal` creditando os tokens de volta
- Atualizar token_wallets.balance

## Rotas
```php
Route::middleware(['auth'])->group(function () {
    Route::get('/performer/payouts',         [PayoutController::class, 'index'])  ->name('performer.payouts.index');
    Route::post('/performer/payouts',        [PayoutController::class, 'store'])  ->name('performer.payouts.store');
    Route::get('/performer/payouts/history', [PayoutController::class, 'history'])->name('performer.payouts.history');
});

// Webhook (sem auth, validado por token)
Route::post('/webhooks/asaas/transfer', [AsaasTransferWebhookController::class, 'handle']);
```

## AsaasService — método novo
```php
public function createTransfer(array $data): array
// $data: ['pix_key', 'pix_key_type', 'value', 'description']
// Endpoint Asaas: POST /transfers
// Retorna: ['id', 'status', 'transferFee']
```

## Frontend

### Pages/Performer/Payouts/Index.vue
- Card: saldo disponível em tokens + equivalente BRL estimado
- Formulário de saque:
  - Input: quantidade de tokens (min 500)
  - Select: tipo de chave PIX (CPF / E-mail / Telefone / Aleatória)
  - Input: chave PIX
  - Preview dinâmico: "Você receberá ~R$ XX,XX"
  - Botão "Solicitar saque"
- Aviso se KYC não for active: "Complete a verificação para sacar"
- Lista dos últimos 5 saques com status badge

### Pages/Performer/Payouts/History.vue
Tabela paginada:
- Colunas: Tokens | Valor BRL | Chave PIX (mascarada) | Status | Data
- Status badge: pending=gold, processing=azul, paid=verde, failed=vermelho
- Chave PIX mascarada: ex. CPF "123.***.***-45", email "ro***@gmail.com"

## Regras críticas

### Ledger (não negociável)
- Débito de tokens em `TokenService::debit()` com DB::transaction + lock
- Débito NUNCA deixa saldo negativo
- Estorno via linha nova no ledger (tipo `payout_reversal`) — nunca UPDATE

### Segurança
- Valor BRL sempre calculado no servidor (tokens × 0.099 × split_pct)
- split_pct lido de performer_profiles, nunca do request
- Chave PIX mascarada em todas as responses públicas
- Webhook validado por token (mesmo padrão do webhook Asaas existente)
- audit_log em toda solicitação e mudança de status

## Testes Pest (mínimo 15)
```php
// Acesso
it('performer ativo acessa payouts')
it('performer sem kyc nao pode solicitar saque')
it('consumer nao acessa rota de payout')

// Validação
it('saque abaixo de 500 tokens e rejeitado')
it('saque acima de 50000 tokens e rejeitado')
it('saldo insuficiente e rejeitado')

// Cálculo
it('valor brl e calculado pelo servidor nao pelo request')
it('split_pct e lido do performer_profile nao do request')

// Ledger
it('tokens sao debitados do ledger ao solicitar saque')
it('saldo nao fica negativo em race condition')

// Webhook
it('transfer_paid marca payout como paid')
it('transfer_failed marca payout como failed e estorna tokens')
it('webhook e idempotente por event_id')
it('estorno cria linha no ledger tipo payout_reversal')

// Segurança
it('chave pix de outro performer nao e acessivel')
```

## Checklist security-reviewer
- [ ] Valor BRL nunca aceito do request
- [ ] split_pct nunca aceito do request
- [ ] Débito atômico com lock de linha (sem race condition)
- [ ] Estorno garantido em caso de falha no Asaas
- [ ] Chave PIX mascarada nas responses
- [ ] Webhook idempotente
- [ ] audit_log em todas as operações financeiras
- [ ] Performer só vê seus próprios payouts (sem IDOR)
