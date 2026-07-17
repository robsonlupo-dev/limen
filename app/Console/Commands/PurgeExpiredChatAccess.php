<?php

namespace App\Console\Commands;

use App\Services\ChatAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeExpiredChatAccess extends Command
{
    protected $signature = 'chat:purge-expired-access';

    protected $description = 'Marca acessos ao chat como expired/deleted e soft-deleta as mensagens após a carência (retidas no servidor, ocultas na UI).';

    public function handle(ChatAccessService $service): int
    {
        $result = $service->purgeExpired();

        $this->info(sprintf(
            'expired=%d purged=%d messages_soft_deleted=%d',
            $result['expired'],
            $result['purged'],
            $result['messages_deleted'],
        ));

        // Soft-delete de conteúdo sensível: deixa rastro operacional.
        Log::info('chat:purge-expired-access', $result);

        return self::SUCCESS;
    }
}
