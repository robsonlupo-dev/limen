# Skill: performer-dashboard

## Propósito
Regras e padrões para o painel privado do performer no Limen.

## Rotas
- `GET /performer/dashboard` → `performer.dashboard`
- Protegida por middleware `auth` + gate `performer-active`

## Gate / Policy
```php
// Somente role=performer E status=active
Gate::define('performer-active', function (User $user) {
    return $user->role === 'performer' && $user->status === 'active';
});
```
- Performer pending, suspended ou banned → 403
- Consumer ou admin → 403

## Controller: DashboardController
Namespace: `App\Http\Controllers\Web\Performer\DashboardController`

Dados retornados via Inertia::render:
```php
[
    'wallet'     => $wallet->balance,           // saldo atual (token_wallets)
    'totalEarned'=> $this->totalEarned($user),  // soma créditos tip/split no ledger
    'tips'       => $this->recentTips($user),   // últimas 10 gorjetas
    'followers'  => $user->performerProfile->followers()->count(),
    'kycStatus'  => $user->performerProfile->kyc_status ?? 'pending',
    'isLive'     => $user->performerProfile->is_live,
]
```

## Regras de dados

### Saldo
- SEMPRE lido de `token_wallets.balance` (cache)
- NUNCA recalcular somando o ledger no controller

### Gorjetas recentes
```php
private function recentTips(User $user): array
{
    return Tip::where('performer_id', $user->id)
        ->with('consumer:id') // só id, nunca nome/email
        ->orderByDesc('created_at')
        ->limit(10)
        ->get()
        ->map(fn($tip) => [
            'amount'     => $tip->amount,
            'fan'        => 'Fã #' . str_pad($tip->consumer_id % 10000, 4, '0', STR_PAD_LEFT),
            'created_at' => $tip->created_at->format('d/m/Y H:i'),
        ])
        ->toArray();
}
```

### Total ganho
```php
private function totalEarned(User $user): int
{
    return TokenLedger::where('user_id', $user->id)
        ->where('type', 'credit')
        ->whereIn('reference_type', ['tip', 'split'])
        ->sum('amount');
}
```

## Frontend: Dashboard.vue
Path: `resources/js/Pages/Performer/Dashboard.vue`

### Cards (grid 2x2)
| Card | Valor | Ícone sugerido |
|------|-------|----------------|
| Saldo | `wallet` tokens | Carteira |
| Total ganho | `totalEarned` tokens | Gráfico |
| Seguidores | `followers` | Pessoas |
| Status KYC | badge colorido | Escudo |

### Badge KYC
- `pending`  → amarelo/gold `#C9A24B`
- `active`   → verde `#22c55e`
- `rejected` → vermelho `#ef4444`

### Tabela de gorjetas
Colunas: Fã | Tokens | Data
- Fã exibido como `"Fã #XXXX"` — nunca nome real
- Vazio: mensagem "Nenhuma gorjeta ainda"

### Botão "Ir ao vivo"
```vue
<button :disabled="kycStatus !== 'active'">
  Ir ao vivo
</button>
```
- Desabilitado + tooltip se KYC não for active

### Design System
- Fundo: `#0A0A0B`, surface: `#16161A`
- Accent gold: `#C9A24B`
- Títulos: Cormorant Garamond
- Corpo: Inter
- Padrão dos outros componentes Vue do projeto

## Testes Pest (mínimo 12)

```php
// Acesso
it('performer ativo acessa o dashboard')
it('performer pending recebe 403')
it('performer suspended recebe 403')
it('consumer nao acessa rota de performer')
it('admin nao acessa rota de performer')
it('visitante nao autenticado e redirecionado para login')

// Dados
it('saldo retornado bate com token_wallets')
it('total ganho soma apenas creditos tip e split')
it('gorjetas aparecem ordenadas por data desc')
it('gorjetas limitadas a 10 itens')
it('remetente anonimizado como Fa #XXXX')
it('nome email e user_id real nao aparecem na resposta')
```

## Segurança (checklist obrigatório)
- [ ] `user_id` real nunca exposto na response JSON
- [ ] `email`, `name`, `cpf` do consumidor nunca expostos
- [ ] Gate verificado no controller (não só na rota)
- [ ] Nenhum dado de `identity_verifications` exposto
- [ ] Subagente `security-reviewer` deve rodar antes do commit
