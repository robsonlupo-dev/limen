<?php

namespace App\Services;

use App\Exceptions\ImageProcessingException;
use App\Exceptions\MemberPhotoException;
use App\Models\MemberPhoto;
use App\Models\MemberPhotoAccess;
use App\Models\PerformerProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ciclo de vida da foto efêmera do membro: cap, prazo, leitura e destruição.
 * Ver docs/SECURITY_ISSUES.md § 1.1–§ 1.11.
 *
 * ── As regras vivem AQUI, não nos controllers ───────────────────────────────
 * Mesma razão do item 9 do CLAUDE.md (o guard do Ghost Mode no
 * ProfileVisitService, não nos dois controllers que o chamam): a segunda porta
 * de entrada que aparecesse — API Sanctum, painel de admin, um comando —
 * nasceria sem o cap ou sem a checagem de prazo. Os endpoints de upload/revoke/
 * serve (PR 3) só delegam.
 *
 * ── Modo Discreto e Ghost Mode NÃO bloqueiam (§ 1.11) ───────────────────────
 * E isto é regra escrita, não omissão. `ProfileVisitService::record()` barra o
 * membro discreto porque visita é LISTAGEM — a performer o veria sem que ele
 * tivesse feito nada. Mandar a própria foto é o oposto: auto-listagem
 * voluntária, com aviso na tela de envio (§ 1.1). Bloquear aqui tiraria do
 * membro discreto uma escolha que é dele. Não "consertar" em nenhum dos dois
 * sentidos sem passar pelo PO.
 */
class MemberPhotoService
{
    /**
     * Folga antes de uma foto vencida-e-ainda-presente virar alarme.
     *
     * O GC roda de hora em hora, então encontrar arquivos vencidos é o trabalho
     * normal dele. O que NÃO é normal é encontrar arquivos que já estavam
     * vencidos na rodada anterior: significa que aquela rodada não os removeu.
     * 2h é uma janela de GC inteira de folga — número persistente diferente de
     * zero é o alarme do § 1.3, e hoje não há alerta de job em lugar nenhum.
     */
    public const STALE_AFTER_HOURS = 2;

    /** Teto do nome original guardado. Ver sanitizeFilename(). */
    private const FILENAME_MAX = 120;

    public function __construct(private MemberPhotoStore $store) {}

    /**
     * Recebe a foto do membro. Aplica o cap de ativas ANTES de existir linha.
     *
     * ── O cap é autorização, e por isso é no submit (§ 1.6) ─────────────────
     * Verificar na limpeza deixaria a janela aberta justo onde o abuso acontece:
     * o GC roda de hora em hora, então um laço de upload gravaria centenas de
     * arquivos e só seria podado no tick seguinte — o disco já teria enchido.
     *
     * ── E por que sob lock ──────────────────────────────────────────────────
     * `count()` seguido de `insert()` não é um cap: dois submits concorrentes
     * leem 4 e gravam os dois, e 5 vira 6. O lock é na linha do TITULAR, e não
     * nas fotos: as linhas que fariam o cap estourar são justamente as que ainda
     * não existem, e não há o que travar numa linha inexistente. Travando o
     * dono, os dois submits do mesmo membro serializam. Mesmo `lockForUpdate` do
     * recovery code de 2FA.
     *
     * ── Ordem: bytes primeiro, linha depois ─────────────────────────────────
     * O arquivo é gravado FORA da transação — higienizar e cifrar são o passo
     * caro, e segurá-lo com um lock de linha aberto serializaria uploads
     * simultâneos do mesmo membro por segundos. A contrapartida é a compensação
     * no catch: se o cap (ou qualquer falha) derrubar a transação, os bytes
     * recém-gravados vão embora junto. O inverso — linha primeiro, arquivo
     * depois — deixaria uma foto que a tela lista e o serving não consegue
     * abrir, que é pior: o membro acharia que compartilhou algo que não existe.
     *
     * @param  int  $ttlHours  um de MemberPhoto::TTL_HOURS
     *
     * @throws MemberPhotoException cap estourado ou TTL fora do menu
     * @throws ImageProcessingException imagem recusada
     */
    public function create(User $member, UploadedFile $file, int $ttlHours): MemberPhoto
    {
        if (! in_array($ttlHours, MemberPhoto::TTL_HOURS, true)) {
            throw MemberPhotoException::invalidTtl($ttlHours);
        }

        $path = $this->store->store($file, $member->id);

        try {
            return DB::transaction(function () use ($member, $file, $path, $ttlHours) {
                User::query()->whereKey($member->getKey())->lockForUpdate()->first();

                // MESMO critério do serving (escopo do model): contar linha já
                // vencida travaria o membro em 5 fotos mortas esperando o GC, e
                // o cap viraria bug de produto em vez de defesa.
                $active = MemberPhoto::query()->activeForUser($member->id)->count();

                if ($active >= MemberPhoto::ACTIVE_LIMIT) {
                    throw MemberPhotoException::activeLimitReached();
                }

                $photo = new MemberPhoto([
                    'expires_at' => now()->addHours($ttlHours),
                    'size_bytes' => $this->store->size($path),
                ]);

                // Fora do fillable: dono vem do request autenticado, caminho vem
                // do Store. Nenhum dos dois aceita payload.
                $photo->user_id = $member->id;
                $photo->path_encrypted = $path;
                $photo->original_filename = $this->sanitizeFilename($file->getClientOriginalName());
                $photo->save();

                return $photo;
            });
        } catch (Throwable $e) {
            $this->store->delete($path);

            throw $e;
        }
    }

    /**
     * Dá (ou renova) o acesso de uma performer a uma foto.
     *
     * O prazo do acesso é o MENOR entre o pedido e o da foto: acesso não
     * sobrevive ao conteúdo. Sem o clamp, um grant de 7 dias sobre uma foto de
     * 24h deixaria uma linha "ativa" apontando para bytes que o GC já levou — e
     * a tela da performer mostraria "Expira nesta semana" para uma foto morta.
     *
     * Renova em vez de empilhar (índice único do par): o agregado que o membro
     * vê é "com quantas PERFORMERS você compartilhou" (§ 1.1), não quantos
     * grants ele emitiu.
     */
    public function grantTo(MemberPhoto $photo, PerformerProfile $profile, ?int $ttlHours = null): MemberPhotoAccess
    {
        $requested = $ttlHours === null
            ? $photo->expires_at
            : now()->addHours($ttlHours);

        $expiresAt = $requested->greaterThan($photo->expires_at)
            ? $photo->expires_at
            : $requested;

        return MemberPhotoAccess::updateOrCreate(
            [
                'member_photo_id' => $photo->id,
                'performer_profile_id' => $profile->id,
            ],
            [
                'granted_at' => now(),
                'expires_at' => $expiresAt,
            ],
        );
    }

    /**
     * Bytes da foto para o TITULAR.
     *
     * @throws MemberPhotoException vencida ou já destruída
     */
    public function readForMember(MemberPhoto $photo): string
    {
        if ($photo->trashed() || $photo->isExpired()) {
            throw MemberPhotoException::expired();
        }

        return $this->store->retrieve($photo->path_encrypted);
    }

    /**
     * Bytes da foto para a PERFORMER que recebeu o acesso.
     *
     * Confere os DOIS prazos, e é aqui que o TTL vale (§ 1.3): se o único
     * mecanismo que cortasse o acesso fosse o job apagando o arquivo, um job
     * parado não custaria disco — custaria privacidade. O job é só garbage
     * collection; quem nega é esta função.
     *
     * `$access->photo` já vem sem as soft-deletadas (escopo global do
     * SoftDeletes), então foto destruída cai no mesmo `null` de foto inexistente.
     *
     * @throws MemberPhotoException vencido de qualquer um dos dois lados
     */
    public function readForPerformer(MemberPhotoAccess $access): string
    {
        $photo = $access->photo;

        if ($photo === null || $photo->isExpired() || $access->isExpired()) {
            throw MemberPhotoException::expired();
        }

        $bytes = $this->store->retrieve($photo->path_encrypted);

        // Depois de entregar: marcar antes e falhar na leitura registraria uma
        // visualização que não aconteceu.
        $access->markViewed();

        return $bytes;
    }

    /**
     * Destrói UMA foto: bytes do disco, acessos do banco, linha soft-deletada.
     *
     * É o primitivo que o GC e o encerramento de conta (PR 4) compartilham —
     * duas cópias divergiriam, e a que esquecesse os acessos deixaria de pé o
     * mapa de quem mostrou o rosto para quem (§ 1.8).
     *
     * A ordem é bytes → banco. Falha ao apagar o arquivo ABORTA e deixa a linha
     * de pé, para a rodada seguinte tentar de novo: soft-deletar aqui esconderia
     * do GC um arquivo que continua no disco, e o alarme de vencidas-e-presentes
     * nunca mais o veria.
     *
     * O DELETE dos acessos é explícito porque o `cascadeOnDelete` da FK **não
     * dispara**: a foto sai por soft delete (item 11 do CLAUDE.md, verbatim).
     */
    public function destroy(MemberPhoto $photo): void
    {
        $this->store->delete($photo->path_encrypted);

        DB::transaction(function () use ($photo) {
            $photo->accesses()->delete();
            $photo->delete();
        });
    }

    /**
     * Garbage collection das fotos vencidas.
     *
     * Não é o mecanismo de expiração — é o que recolhe depois dele (§ 1.3).
     *
     * `stale` é o alarme: fotos que já estavam vencidas na rodada anterior e
     * continuam com arquivo no disco. Em operação normal é zero, porque cada
     * rodada limpa o que venceu na sua hora. Persistentemente diferente de zero
     * significa que o GC não está conseguindo apagar — e como a expiração vale
     * na leitura, isso é custo de disco, não de privacidade.
     *
     * @return array{expired:int,deleted:int,stale:int,failed:int}
     */
    public function purgeExpired(): array
    {
        $counts = ['expired' => 0, 'deleted' => 0, 'stale' => 0, 'failed' => 0];
        $staleBefore = now()->subHours(self::STALE_AFTER_HOURS);

        MemberPhoto::query()
            ->where('expires_at', '<=', now())
            ->chunkById(200, function ($photos) use (&$counts, $staleBefore) {
                foreach ($photos as $photo) {
                    $counts['expired']++;

                    if ($photo->expires_at->lessThan($staleBefore) && $this->store->exists($photo->path_encrypted)) {
                        $counts['stale']++;
                    }

                    try {
                        $this->destroy($photo);
                        $counts['deleted']++;
                    } catch (Throwable $e) {
                        $counts['failed']++;

                        // Id da LINHA, nunca o caminho nem o titular: o log não
                        // é lugar de mapa de quem mandou foto (princípio 4).
                        Log::warning('member-photos:purge falhou em uma foto', [
                            'member_photo_id' => $photo->id,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $counts;
    }

    /**
     * O nome original é decorativo — só volta para o próprio titular.
     *
     * `basename()` porque o cliente escolhe a string inteira e ela nunca deve
     * poder virar caminho (o caminho no disco é gerado pelo Store, mas um nome
     * com barra vazaria como diretório em qualquer tela que o exiba). O corte de
     * comprimento não é cosmético: a coluna é cifrada, e o ciphertext cresce com
     * a entrada.
     */
    private function sanitizeFilename(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        // \p{C}: controles e ZWSP — o mesmo achado da revisão do filtro de chat,
        // onde caractere invisível atravessava a normalização.
        $clean = preg_replace('/\p{C}+/u', '', basename($name)) ?? '';
        $clean = trim($clean);

        return $clean === '' ? null : Str::limit($clean, self::FILENAME_MAX, '');
    }
}
