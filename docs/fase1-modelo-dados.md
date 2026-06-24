# Fase 1 — Modelo de Dados + Segurança de Base (Limen)

Fundação do banco. Tudo que vem depois (cadastro, KYC, tokens, pagamento) se apoia aqui.
Aprendizados aplicados do LiveCamBR: saldo NUNCA é coluna mutável, PII isolada e criptografada,
pagamento com idempotência. Dinheiro e tokens sempre como inteiros.

## Escopo desta fase
- Migrations das tabelas do núcleo.
- Models Eloquent com relações e casts (incl. criptografia de PII).
- Form Request de cadastro de cliente (18+, consentimento LGPD, regras de senha).
- `TokenService` mínimo (credit/debit/balance) com a regra do ledger append-only.
- Seeder de pacotes de tokens + usuários sintéticos (sem PII real).
- Testes (Pest) das invariantes críticas.

## Convenções
- Tabelas em inglês, plural, snake_case. FKs e índices em tudo que consulta.
- `decimal`/float proibido para dinheiro. Use inteiro: tokens (unidade) e `*_cents` (centavos).
- Soft delete onde a LGPD exige janela de auditoria.

---

## Tabelas

### 1. users  (estende a tabela padrão do Laravel)
| coluna | tipo | notas |
|--------|------|-------|
| id | bigint PK | |
| name | string | |
| email | string unique | |
| email_verified_at | timestamp null | |
| password | string | bcrypt |
| role | enum('consumer','performer','admin') | default 'consumer' |
| phone | string null | |
| phone_verified_at | timestamp null | |
| birthdate | date null | base do gate 18+ |
| age_verified_at | timestamp null | preenchido só após KYC (Fase 4) |
| lgpd_consent_at | timestamp null | momento do aceite |
| terms_version | string null | versão dos termos aceitos |
| status | enum('pending','active','suspended','banned') | consumer→active; performer→pending |
| last_login_at | timestamp null | |
| remember_token, timestamps, deleted_at (soft delete) | | |

> PII pesada (CPF, documentos) NÃO fica aqui — vai em `identity_verifications`.

### 2. performer_profiles  (1:1 com users role=performer)
| coluna | tipo | notas |
|--------|------|-------|
| id | bigint PK | |
| user_id | bigint FK→users unique | onDelete cascade |
| stage_name | string | nome artístico |
| bio | text null | |
| category | enum('mulheres','homens','casais','trans','gls','swing') | default 'mulheres' |
| work_modes | json | ['streaming','videos','dating'] |
| level | enum('iniciante','estrela','premium','vip') | provisório (ver D9) |
| split_pct | unsignedTinyInteger | % do performer; default 65 (ver D8) |
| rate_public | unsignedInteger | tokens; default 60 |
| rate_private | unsignedInteger | default 120 |
| rate_camera | unsignedInteger | default 20 |
| is_live | boolean default false | |
| is_verified | boolean default false | |
| rating_avg | decimal(3,2) default 0 | só exibição, não dinheiro |
| rating_count | unsignedInteger default 0 | |
| followers_count | unsignedInteger default 0 | |
| avatar_path, cover_path | string null | storage privado |
| timestamps, deleted_at | | |

### 3. identity_verifications  (SENSÍVEL — isolada e criptografada)
| coluna | tipo | notas |
|--------|------|-------|
| id | bigint PK | |
| user_id | bigint FK→users | |
| document_type | enum('cpf','rg','cnh') | |
| document_number | text | **encrypted cast** |
| full_legal_name | text | **encrypted** |
| date_of_birth | text | **encrypted** |
| document_front_path, document_back_path, selfie_path | string null | **storage privado**, nunca público |
| provider | string null | provedor de KYC (Fase 4) |
| provider_reference | string null | id no provedor |
| provider_status | string null | |
| status | enum('pending','approved','rejected','review') default 'pending' | |
| age_confirmed | boolean default false | prova de idade |
| reviewed_by | bigint FK→users null | admin que revisou |
| reviewed_at | timestamp null | |
| timestamps | | |

> Esta tabela é a guarda de prova de idade (equivalente "2257"). Acesso restrito por Policy.

### 4. token_wallets  (saldo em cache — derivado do ledger)
| coluna | tipo | notas |
|--------|------|-------|
| id | bigint PK | |
| user_id | bigint FK→users unique | |
| balance | bigint default 0 | em tokens; só alterado junto com um insert no ledger, dentro de transação com lock |
| timestamps | | |

### 5. token_ledger  (APPEND-ONLY — coração do dinheiro)
| coluna | tipo | notas |
|--------|------|-------|
| id | bigint PK | |
| wallet_id | bigint FK→token_wallets | |
| entry_type | enum('purchase','spend_tip','spend_private','spend_camera','payout_reserve','refund','bonus','adjustment') | |
| amount | bigint | sinalizado: + crédito, − débito |
| balance_after | bigint | snapshot do saldo após o lançamento |
| reference_type | string null | 'payment','tip','payout'... |
| reference_id | bigint null | |
| description | string null | |
| created_at | timestamp | **sem updated_at, sem soft delete — imutável** |

> Regra de ferro: linhas do ledger NUNCA são editadas ou apagadas. Índice em (wallet_id, created_at).

### 6. token_packages
| coluna | tipo | notas |
|--------|------|-------|
| id | bigint PK | |
| slug | string unique | 'bronze','prata'... |
| name | string | |
| tokens | unsignedInteger | |
| price_cents | unsignedInteger | preço em centavos |
| active | boolean default true | |
| sort_order | unsignedInteger default 0 | |
| timestamps | | |

### 7. payments
| coluna | tipo | notas |
|--------|------|-------|
| id | bigint PK | |
| user_id | bigint FK→users | |
| token_package_id | bigint FK→token_packages null | |
| provider | enum('asaas') default 'asaas' | |
| provider_charge_id | string null unique | id da cobrança no Asaas |
| method | enum('pix') default 'pix' | |
| amount_cents | unsignedInteger | |
| tokens | unsignedInteger | |
| status | enum('pending','confirmed','failed','refunded','expired') default 'pending' | |
| pix_qr_code | text null | |
| pix_copy_paste | text null | |
| expires_at | timestamp null | |
| confirmed_at | timestamp null | |
| timestamps | | |

### 8. payment_events  (idempotência de webhook)
| coluna | tipo | notas |
|--------|------|-------|
| id | bigint PK | |
| provider | string | |
| provider_event_id | string unique | **chave de dedup** — garante processar 1x |
| payment_id | bigint FK→payments null | |
| payload | json | |
| processed_at | timestamp null | |
| created_at | timestamp | |

### 9. audit_logs
| coluna | tipo | notas |
|--------|------|-------|
| id | bigint PK | |
| user_id | bigint FK→users null | quem fez a ação |
| action | string | ex.: 'verification.approved' |
| subject_type, subject_id | string/bigint null | alvo |
| ip | string null | |
| metadata | json null | |
| created_at | timestamp | |

---

## TokenService (mínimo, mas com as invariantes certas)
- `balance(user)` → saldo do wallet.
- `credit(user, amount, type, ref, desc)` e `debit(user, amount, type, ref, desc)`:
  - Abrir transação; `SELECT ... FOR UPDATE` no wallet (lock de linha).
  - Calcular novo saldo; **débito nunca pode deixar saldo negativo** (lança exceção).
  - Inserir linha no `token_ledger` com `balance_after`.
  - Atualizar `token_wallets.balance`.
  - Commit. Tudo atômico.

## Seeder (sem PII real)
- `token_packages` (valores provisórios, ver D8):
  bronze 200/R$24,90 · prata 500/R$49,90 · ouro 1200/R$99,90 · platina 2000/R$149,90 · diamante 2500/R$179,90 · black 6000/R$399,90.
- Uns 3 usuários sintéticos (1 admin, 1 performer pending, 1 consumer) com dados fake.

## Testes (Pest) obrigatórios
1. credit depois debit → saldo e ledger batem; `balance_after` correto.
2. debit acima do saldo → lança exceção, nada é gravado.
3. cadastro de menor de 18 → rejeitado pela validação.
4. cadastro sem aceite de termos → rejeitado.
5. ledger é append-only → não há caminho que faça update/delete de linha.

## Decisões pendentes (NÃO bloqueiam esta fase)
- **D8** valores canônicos do token/pacotes/split — seeder usa provisório.
- **D9** nomes dos níveis de performer — enum usa provisório.
