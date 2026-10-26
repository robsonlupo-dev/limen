<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente mínimo da API do Chatwoot (CRM de operação, servidor do Contabo). Só o
 * que o opt-in de WhatsApp precisa: criar/atualizar um contato na caixa de entrada
 * e aplicar um rótulo. Desligado por padrão (`chatwoot.enabled=false`) — quando
 * desligado, `isEnabled()` é false e o job que o usa vira no-op, sem nunca derrubar
 * o fluxo do opt-in (o banco é a fonte da verdade). Ver `config/chatwoot.php`.
 */
class ChatwootClient
{
    public function isEnabled(): bool
    {
        return (bool) config('chatwoot.enabled')
            && filled(config('chatwoot.base_url'))
            && filled(config('chatwoot.account_id'))
            && filled(config('chatwoot.inbox_id'))
            && filled(config('chatwoot.api_token'));
    }

    /**
     * Cria (ou atualiza) um contato pelo número E.164 e aplica os rótulos. Devolve
     * o id do contato no Chatwoot, ou null se desligado/falhou (o chamador decide o
     * que fazer — o push nunca é crítico para o opt-in).
     *
     * @param  array<int, string>  $labels
     */
    public function upsertContact(string $name, string $e164Phone, array $labels = []): ?int
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $contactId = $this->createOrFindContact($name, $e164Phone);

            if ($contactId !== null && $labels !== []) {
                $this->applyLabels($contactId, $labels);
            }

            return $contactId;
        } catch (\Throwable $e) {
            Log::warning('chatwoot.upsert_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function createOrFindContact(string $name, string $e164Phone): ?int
    {
        $resp = $this->http()->post($this->accountUrl('/contacts'), [
            'name' => $name,
            'phone_number' => $e164Phone,
            'inbox_id' => (int) config('chatwoot.inbox_id'),
        ]);

        // 200/201 → criado; o id vem em payload.contact.id (varia por versão).
        if ($resp->successful()) {
            return $resp->json('payload.contact.id')
                ?? $resp->json('payload.id')
                ?? $resp->json('id');
        }

        // 422 → já existe com esse telefone: busca pelo número e reaproveita.
        if ($resp->status() === 422) {
            $search = $this->http()->get($this->accountUrl('/contacts/search'), [
                'q' => $e164Phone,
            ]);

            if ($search->successful()) {
                return $search->json('payload.0.id');
            }
        }

        Log::warning('chatwoot.create_contact_failed', ['status' => $resp->status()]);

        return null;
    }

    /** @param  array<int, string>  $labels */
    private function applyLabels(int $contactId, array $labels): void
    {
        $this->http()->post($this->accountUrl("/contacts/{$contactId}/labels"), [
            'labels' => array_values($labels),
        ]);
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['api_access_token' => (string) config('chatwoot.api_token')])
            ->timeout((int) config('chatwoot.timeout', 8))
            ->acceptJson();
    }

    private function accountUrl(string $path): string
    {
        $base = (string) config('chatwoot.base_url');
        $account = (string) config('chatwoot.account_id');

        return "{$base}/api/v1/accounts/{$account}".$path;
    }
}
