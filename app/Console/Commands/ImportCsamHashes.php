<?php

namespace App\Console\Commands;

use App\Models\CsamHash;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Importa hashes de CSAM de um CSV (Sprint 16). Usado quando a parceria com
 * NCMEC/IWF existir (requer CNPJ) — por ora a lista sobe vazia.
 *
 * CSV: uma coluna `hash` obrigatória, `source` opcional (default da config).
 * Aceita com ou sem cabeçalho: se a 1ª linha não tiver um cabeçalho `hash`,
 * trata todas as linhas como dados (1ª coluna = hash, 2ª = source).
 *
 * Idempotente: `hash` é único; reimportar não duplica (upsert por hash). NÃO loga
 * os hashes (é a própria lista de bloqueio).
 */
class ImportCsamHashes extends Command
{
    protected $signature = 'csam:import-hashes {file : Caminho do CSV} {--source= : Fonte a gravar (default: config)}';

    protected $description = 'Importa hashes de CSAM de um CSV para a lista local.';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Arquivo não encontrado ou ilegível: {$file}");

            return self::FAILURE;
        }

        $defaultSource = $this->option('source') ?: config('csam.default_source', 'manual');

        $handle = fopen($file, 'r');
        if ($handle === false) {
            $this->error('Falha ao abrir o CSV.');

            return self::FAILURE;
        }

        $rows = [];
        $now = now();
        $line = 0;

        while (($cols = fgetcsv($handle)) !== false) {
            $line++;

            $hash = trim((string) ($cols[0] ?? ''));

            // Pula o cabeçalho (1ª linha literalmente "hash") e linhas vazias.
            if ($hash === '' || ($line === 1 && strtolower($hash) === 'hash')) {
                continue;
            }

            $rows[] = [
                'hash' => $hash,
                'source' => trim((string) ($cols[1] ?? '')) ?: $defaultSource,
                'added_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        fclose($handle);

        if ($rows === []) {
            $this->info('Nenhum hash para importar.');

            return self::SUCCESS;
        }

        // Upsert por hash (único): reimportar é idempotente; a fonte é atualizada.
        $imported = 0;
        foreach (array_chunk($rows, 1000) as $chunk) {
            CsamHash::upsert($chunk, ['hash'], ['source', 'added_at', 'updated_at']);
            $imported += count($chunk);
        }

        // NÃO loga os hashes (é a lista de bloqueio) — só a contagem.
        Log::info('csam:import-hashes', ['imported' => $imported, 'source' => $defaultSource]);
        $this->info("Importados {$imported} hash(es) (fonte: {$defaultSource}).");

        return self::SUCCESS;
    }
}
