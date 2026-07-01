# Skill: token-purchase-ui

## Propósito
Regras e padrões para a UI de compra de tokens no Limen (Fase 10).
Complementa as skills `asaas-pix-integration` e `token-ledger-rules`.

## Pacotes de tokens (D8 fixado)
| Slug      | Nome     | Tokens | Preço (R$) | Bônus |
|-----------|----------|--------|------------|-------|
| bronze    | Bronze   | 100    | 9,90       | —     |
| prata     | Prata    | 250    | 24,90      | +25   |
| ouro      | Ouro     | 500    | 49,90      | +75   |
| platina   | Platina  | 1000   | 99,90      | +200  |
| diamante  | Diamante | 2500   | 249,90     | +600  |
| black     | Black    | 5000   | 499,90     | +1500 |

Token = R$ 0,099 unitário (base). Bônus já incluso nos totais acima.

## Rotas
```php
// Wallet / pacotes
GET  /wallet                    → wallet.index       (consumer autenticado)
POST /wallet/purchase/{package} → wallet.purchase    (inicia compra)
GET  /wallet/pending            → wallet.pending     (polling status)
GET  /wallet/history            → wallet.history     (histórico ledger)
```

## Controller: WalletController
Namespace: `App\Http\Controllers\Web\Consumer\WalletController`

### index()
Retorna via Inertia:
```php
[
    'balance'  => $wallet->balance,
    'packages' => TokenPackage::where('is_active', true)->orderBy('tokens')->get(),
    'recent'   => $this->recentLedger($user, 5),
]
```

### purchase(Request $request, TokenPackage $package)
1. Validar que package está ativo
2. Chamar `PaymentService::createPixCharge($user, $package)` (já existe da Fase 3)
3. Retornar `{ payment_id, pix_code, pix_qr_base64, expires_at }`
4. NUNCA aceitar valor ou tokens do request — sempre do servidor

### pending(Request $request)
- Recebe `?payment_id=X`
- Consulta `Payment::where('id', $id)->where('user_id', auth()->id())`
- Retorna `{ status, balance }` para polling do frontend
- Status possíveis: `pending | paid | expired | failed`

## Frontend

### Pages/Consumer/Wallet/Index.vue
Layout em 3 seções:

**1. Header da Wallet**
```
Seu saldo: [balance] tokens
[Ver histórico] — link para wallet.history
```

**2. Grid de pacotes (3 colunas desktop, 1 mobile)**
Cada card:
- Nome do pacote (Cormorant Garamond, gold)
- Quantidade de tokens em destaque
- Bônus badge (se > 0): "+X tokens bônus" em verde
- Preço em BRL
- Botão "Comprar com PIX" → abre modal

**3. Histórico recente** (últimas 5 entradas do ledger)
- Tipo | Tokens | Data

### Components/PixModal.vue
Exibido após `purchase()` com sucesso:
```
[QR Code PIX — imagem base64]
[Código copia-e-cola — input readonly + botão copiar]
Expira em: [countdown HH:MM:SS]
Status: [badge animado — aguardando / pago / expirado]
[Botão fechar]
```
- Polling a cada 3s em `wallet.pending?payment_id=X`
- Ao receber `status=paid`: atualiza saldo, fecha modal, toast "Tokens creditados!"
- Ao receber `status=expired`: para polling, badge vermelho "PIX expirado"

### Pages/Consumer/Wallet/History.vue
Tabela paginada do ledger do consumer:
- Colunas: Tipo | Tokens | Saldo após | Data
- Tipos traduzidos: `purchase`→"Compra", `tip`→"Gorjeta enviada"
- Paginação Laravel padrão

## Design System (obrigatório)
- Fundo: `#0A0A0B`, surface cards: `#16161A`
- Accent gold: `#C9A24B` — usado em preços e títulos de pacote
- Bônus badge: verde `#22c55e`
- Botão PIX: fundo gold, texto preto, hover mais escuro
- Cormorant Garamond para nome/tokens dos pacotes
- Inter para preços, botões, histórico
- QR Code: border gold, fundo branco (para leitura do QR)
- Pacote em destaque (Ouro): borda gold mais espessa + badge "Popular"

## Regras críticas

### Segurança
- Valor e quantidade de tokens SEMPRE do servidor (TokenPackage)
- Nunca do body do request
- Payment pertence ao user autenticado (sempre filtrar por user_id)
- Polling endpoint verifica `user_id` antes de retornar qualquer dado
- Idempotência: reutilizar charge existente se já houver `pending` para o mesmo package+user nas últimas 2h (evitar cobranças duplas)

### Ledger
- Crédito de tokens APENAS via webhook Asaas (Fase 3) — nunca no `purchase()`
- O `purchase()` só cria o charge no Asaas e retorna o PIX
- Saldo atualizado só após `payment_events` processar o webhook

## Testes Pest (mínimo 14)

```php
// Acesso
it('consumer autenticado ve a wallet')
it('performer nao acessa rota de consumer wallet')
it('visitante e redirecionado para login')

// Pacotes
it('lista apenas pacotes ativos')
it('purchase rejeita package inativo')
it('purchase retorna pix_code e qr_base64')
it('purchase nao aceita valor do request')
it('purchase retorna mesmo charge se ja existe pending recente')

// Polling
it('pending retorna status correto para payment do proprio user')
it('pending nao expoe payment de outro user')
it('pending retorna balance atualizado quando status=paid')

// Histórico
it('history lista entradas do ledger do consumer paginadas')
it('history nao expoe entradas de outro user')

// Segurança
it('tokens creditados sao sempre do package nao do request')
```

## Checklist security-reviewer
- [ ] `user_id` sempre filtrado nas queries de Payment e Ledger
- [ ] Valor monetário sempre do DB, nunca do request
- [ ] QR base64 não expõe dados de outros usuários
- [ ] Polling não tem IDOR (verificar ownership do payment)
- [ ] Nenhum campo sensível (CPF, email) na resposta da wallet
