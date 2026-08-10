<?php

namespace App\Services;

use App\Models\MemberProfileVisit;
use App\Models\PerformerProfile;
use App\Models\ProfileVisit;
use App\Models\User;
use App\Support\FanAlias;
use App\Support\LifestyleTier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class ProfileVisitService
{
    /**
     * Janela de deduplicação. Sem ela, F5 na página do perfil vira uma linha
     * nova por recarga e o painel da performer mostra o mesmo alias vinte
     * vezes — além de dar a ela um cronômetro do tempo que o membro passou ali,
     * que é bem mais do que "quem passou por aqui".
     */
    public const DEDUPE_MINUTES = 30;

    /** Janela mostrada no painel da performer. */
    public const RECENT_HOURS = 24;

    /**
     * Retenção. A tela consome 24h; guardar além disso seria acumular o mapa de
     * interesses de cada membro sem finalidade que o justifique (LGPD, princípio
     * da necessidade). 7 dias dão folga para ampliar a janela do painel sem
     * migração e para investigar abuso recente. Expurgo em `visits:purge`.
     */
    public const RETENTION_DAYS = 7;

    /**
     * Fuso de EXIBIÇÃO das faixas do painel.
     *
     * Fixo, e não `config('app.timezone')`: a app roda em UTC (config/app.php),
     * então derivar a faixa dali rotularia uma visita das 21:00 em São Paulo
     * como "Madrugada". Os rótulos são lidos por gente no Brasil — o fuso da
     * infraestrutura não tem a ver com o significado da palavra.
     */
    public const DISPLAY_TIMEZONE = 'America/Sao_Paulo';

    /**
     * k-anonimato por faixa: quantos aliases distintos uma faixa precisa ter
     * para ser exibida.
     *
     * A faixa de 6h sozinha não segurava a performer que dá REFRESH: ela
     * atualiza o painel, manda o link, atualiza de novo, e o alias que apareceu
     * no intervalo é o alvo — o rótulo grosso não esconde a mudança do conjunto.
     * Com k, a faixa só surge já com k aliases dentro, e o que apareceu no
     * intervalo é um entre k.
     *
     * ATENÇÃO ao alcance: isto protege a transição escondida→visível. Uma faixa
     * JÁ visível que ganha um visitante continua entregando esse visitante por
     * diferença entre dois refreshes. Ver a ressalva no CLAUDE.md.
     */
    public const SLOT_MIN_K = 3;

    public function __construct(private FollowerVisibilityService $visibility) {}

    /**
     * Registra a visita, se for para registrar.
     *
     * Silenciosamente não faz nada quando: não há visitante logado, o visitante
     * não é membro (a própria performer, outra performer, admin), o visitante
     * tem Ghost Mode, ou o visitante está em Modo Discreto. O retorno é só para
     * teste — nenhum chamador decide nada com ele, e a página NÃO deve mudar
     * conforme a visita foi ou não gravada: se a resposta diferisse, o Ghost
     * Mode seria detectável de fora.
     *
     * ATENÇÃO ao ligar prefetch do Inertia nos links de perfil (PerformerCard,
     * PublicPerformerCard): hover viraria visita, e o painel da performer
     * passaria a listar gente que nunca abriu o perfil. Se o prefetch entrar,
     * a gravação tem que sair do GET da página.
     */
    public function record(?User $visitor, PerformerProfile $profile): ?ProfileVisit
    {
        if (! $visitor || $visitor->role !== 'consumer') {
            return null;
        }

        // Modo Discreto é "nunca listado para a performer", e visitante é
        // listagem. Sem esta linha o perk se fura por uma superfície nova — e
        // fura JUSTO no caso que a regra 3 do CLAUDE.md protege: quem ativou
        // Discreto como Black e depois lapsou o tier continua discreto, mas
        // deixa de ser elegível ao Ghost Mode, e voltaria a ser listado aqui
        // por causa do lapso de pagamento.
        if ($visitor->discrete_mode) {
            return null;
        }

        // O perk. Note que a checagem vem ANTES de qualquer escrita: não
        // gravamos "visita oculta" para filtrar depois — ver a migration.
        if ($visitor->hasGhostMode()) {
            return null;
        }

        $now = now();

        $recent = ProfileVisit::where('visitor_id', $visitor->id)
            ->where('performer_profile_id', $profile->id)
            ->where('visited_at', '>=', $now->copy()->subMinutes(self::DEDUPE_MINUTES))
            ->exists();

        if ($recent) {
            return null;
        }

        $visit = new ProfileVisit([
            'performer_profile_id' => $profile->id,
            'visited_at' => $now,
        ]);
        // Sempre do request autenticado, nunca do payload.
        $visit->visitor_id = $visitor->id;
        $visit->save();

        return $visit;
    }

    /**
     * Registra o SENTIDO INVERSO: a performer abriu o perfil de um membro no
     * catálogo dela (A.0.4, visitas bidirecionais). O membro verá "quem visitou
     * seu perfil" — memberVisitorsPanelFor().
     *
     * Por que este caminho NÃO herda os guards de record():
     *  - NÃO checa Ghost Mode / Modo Discreto: aqueles perks protegem o MEMBRO
     *    de ser listado PARA A PERFORMER (record() é membro→performer). Aqui quem
     *    é exposto é a PERFORMER — pública, sem esse perk. Ausência de linha não é
     *    produto de privacidade nenhum neste sentido.
     *  - O visitante é a performer; o `record()` acima retorna cedo para
     *    não-consumer justamente para não coletar este lado por engano. Este
     *    método é a coleta explícita e separada dele.
     *
     * Guard de borda: só grava visita a MEMBRO de pé (consumer ativo). A
     * performer só alcança membros pelo catálogo, que já filtra isso
     * (MemberCatalogService) — esta é a defesa redundante, não a primária.
     *
     * Dedup de 30min (a MESMA janela do sentido atual): F5 no perfil não vira uma
     * linha nova por recarga nem dá ao membro um cronômetro do tempo que a
     * performer passou ali.
     *
     * O retorno é só para teste — nenhum chamador decide nada com ele.
     */
    public function recordPerformerVisit(PerformerProfile $performerProfile, User $member): ?MemberProfileVisit
    {
        // Defesa de borda: não coletamos visita a quem não é membro ativo.
        if ($member->role !== 'consumer' || $member->status !== 'active') {
            return null;
        }

        $now = now();

        $recent = MemberProfileVisit::where('performer_profile_id', $performerProfile->id)
            ->where('member_id', $member->id)
            ->where('visited_at', '>=', $now->copy()->subMinutes(self::DEDUPE_MINUTES))
            ->exists();

        if ($recent) {
            return null;
        }

        $visit = new MemberProfileVisit(['visited_at' => $now]);
        // Sempre das entidades do request autenticado, nunca do payload.
        $visit->performer_profile_id = $performerProfile->id;
        $visit->member_id = $member->id;
        $visit->save();

        return $visit;
    }

    /**
     * "Quem visitou seu perfil" — as PERFORMERS que visitaram este membro.
     *
     * Assimetria deliberada com panelFor(): a performer é PÚBLICA, então o membro
     * vê a identidade REAL dela (nome artístico, slug, avatar por rota assinada) —
     * sem FanAlias, sem piso de anonimato, sem k por faixa. É o mesmo contrato da
     * lista de corações recebidos (PerformerHeartService::listForMember): quem se
     * expõe aqui é a performer, com a identidade pública do catálogo, nada de PII
     * e nada de tier (M.13.10 é irrelevante neste sentido — não há tier de
     * performer que o membro não possa ver).
     *
     * Uma linha por performer (a visita mais recente): "quem passou por aqui", não
     * "quantas vezes voltou". Só performers de pé (perfil verificado, conta ativa),
     * como os corações: a visita de uma performer que depois foi suspensa/encerrou
     * não aparece.
     *
     * Sem piso e sem k porque não há o que anonimizar: o alvo da exposição é
     * pública por natureza. É seguro mostrar a lista inteira a todo membro (a
     * monetização — gate por tier — é decisão futura de PO, não entra na v1).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function memberVisitorsPanelFor(User $member): Collection
    {
        // Uma linha por performer, com a visita mais recente. Agrupado no banco:
        // trazer a janela inteira para desduplicar em memória seria caro num
        // membro visitado por muitas performers.
        $latest = MemberProfileVisit::where('member_id', $member->id)
            ->where('visited_at', '>=', now()->subDays(self::RETENTION_DAYS))
            ->groupBy('performer_profile_id')
            ->orderByDesc(DB::raw('MAX(visited_at)'))
            ->orderByDesc(DB::raw('MAX(id)'))
            ->get([
                DB::raw('performer_profile_id'),
                DB::raw('MAX(visited_at) as visited_at'),
            ]);

        if ($latest->isEmpty()) {
            return collect();
        }

        // Só performers de pé, com a identidade pública. Uma query para o lote.
        $profiles = PerformerProfile::query()
            ->whereIn('id', $latest->pluck('performer_profile_id'))
            ->where('is_verified', true)
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->get(['id', 'stage_name', 'slug', 'avatar_path'])
            ->keyBy('id');

        return $latest
            ->map(function (object $row) use ($profiles): ?array {
                $profile = $profiles->get((int) $row->performer_profile_id);

                // Performer suspensa/encerrada/em KYC entre a visita e a leitura:
                // some da lista, como um coração dela sumiria.
                if ($profile === null) {
                    return null;
                }

                // Fuso de EXIBIÇÃO, como slot(): `visited_at` está em UTC, e
                // `isToday()`/`format()` sem converter rotulariam uma visita perto
                // da meia-noite de São Paulo no dia errado.
                $visitedAt = Carbon::parse($row->visited_at)->setTimezone(self::DISPLAY_TIMEZONE);

                return [
                    'stage_name' => $profile->stage_name,
                    'slug' => $profile->slug,
                    // Avatar por rota assinada e expirável, chaveada pelo id do
                    // perfil (nunca user_id) — mesma regra do PerformerHeartService
                    // e do PerformerPublicResource.
                    'avatar_url' => $profile->avatar_path
                        ? URL::temporarySignedRoute(
                            'performer.media',
                            now()->addMinutes(60),
                            ['profile_id' => $profile->id, 'type' => 'avatar'],
                        )
                        : null,
                    // Faixa, nunca relógio — a mesma disciplina de horário do resto
                    // do projeto. Só a data quando fora do dia corrente.
                    'visited_slot' => $visitedAt->isToday()
                        ? 'Hoje'
                        : $visitedAt->format('d/m/Y'),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Painel de visitantes recentes da performer.
     *
     * Passa pelo MESMO Piso de Anonimato da tela de seguidores, e por uma
     * razão que não é de simetria: sem piso, uma performer nova manda o link do
     * perfil para uma pessoa, abre o painel e o único alias da janela É aquela
     * pessoa. Como o FanAlias é estável por par (perfil, membro), ela leva esse
     * vínculo para as gorjetas e para a lista de seguidores quando o piso
     * destravar — o piso teria sido contornado por uma tela que nem existia
     * quando ele foi desenhado.
     *
     * Dois cortes, os dois necessários:
     *  - piso de SEGUIDORES (FollowerVisibilityService), que é a regra travada;
     *  - piso de VISITANTES DISTINTOS na janela, porque o primeiro não impede
     *    um painel com um único visitante identificável.
     *
     * O segundo piso conta só visitantes ELEGÍVEIS (conta 7+ dias e e-mail
     * verificado), pelo mesmo critério do piso de seguidores e pela mesma razão:
     * contar todo mundo deixava a performer destravar o próprio painel com
     * contas de véspera. Com 5 seguidores legítimos ela criava 4 contas, visitava
     * o próprio perfil com cada uma, e o quinto alias — o único que ela não
     * plantou — era o visitante real, identificado por eliminação (o horário de
     * cada visita própria casa com a linha correspondente). Como o FanAlias é
     * estável por par, esse vínculo ia junto para as gorjetas e para a lista de
     * seguidores.
     *
     * Elegibilidade decide DESTRAVAR, não filtrar: aberto o painel, a lista sai
     * inteira — visitante de conta nova aparece nela normalmente.
     *
     * O horário sai em FAIXA (`visited_slot`), nunca em relógio; a ordem dentro
     * da faixa é embaralhada; e a faixa só aparece com SLOT_MIN_K aliases —
     * ver slot() e revealableSlots().
     *
     * `visible` continua sendo decidido só pelos PISOS. O k é filtro DENTRO da
     * lista, não substituto do piso: com o painel destravado e toda faixa
     * incompleta, a resposta é `visible: true` com lista vazia. Os dois
     * mecanismos respondem perguntas diferentes — "esta performer pode ver uma
     * lista?" e "esta faixa já dilui quem está nela?".
     *
     * Cada linha leva também o `member_handle` (16 hex do FanAlias), que é o
     * que o Interesse Controlado a partir do painel manda de volta no POST. O
     * `fan` de 4 dígitos NÃO serve para isso: ele colide de propósito (ver
     * FanAlias), e usá-lo como chave mandaria o interesse para a pessoa errada.
     * O `visitor_id` continua sem sair daqui.
     *
     * @return array{visible:bool,visitors:array<int,array{fan:string,member_handle:string,lifestyle:?string,visited_slot:string}>}
     */
    public function panelFor(PerformerProfile $profile, int $limit = 10): array
    {
        $rows = $this->revealableVisitorRows($profile, $limit);

        if ($rows === null) {
            return ['visible' => false, 'visitors' => []];
        }

        return [
            'visible' => true,
            // `visitor_id` é chave interna e não sai do service (item 10 do
            // CLAUDE.md). A tela recebe alias + handle + faixa, nada mais.
            'visitors' => array_map(fn (array $row) => [
                'fan' => $row['fan'],
                'member_handle' => $row['member_handle'],
                'lifestyle' => $row['lifestyle'],
                'visited_slot' => $row['visited_slot'],
            ], $rows),
        ];
    }

    /**
     * Handle do painel → id do membro, ou null.
     *
     * É a porta de entrada do Interesse Controlado a partir do painel, e a
     * regra que a governa é a mesma que o CLAUDE.md trava para a tela de
     * seguidores: **o envio e a tela têm que concordar**. Se discordarem, o par
     * 404/201 do envio vira oráculo para reconstruir o que a tela esconde.
     *
     * Aqui a concordância não é por convenção, é POR CONSTRUÇÃO: este método e
     * o panelFor() consomem a MESMA revealableVisitorRows(), então tudo o que
     * esconde a linha da tela — os dois pisos, o k-anonimato por faixa, o corte
     * em $limit — esconde igualmente o alvo do envio. Duas travessias
     * "equivalentes" divergiriam no primeiro ajuste de uma delas, e a
     * divergência apareceria justamente no sentido permissivo.
     *
     * Por isso o $limit tem que ser o MESMO que a tela usou (o default dos
     * dois): resolver com um limite maior aceitaria alvo que a performer não
     * está vendo, e é exatamente aí que o oráculo nasceria.
     *
     * Ghost Mode e Modo Discreto não precisam de checagem: quem os tem nunca
     * gerou linha em `profile_visits` (ver record()), então não há o que
     * resolver. A ausência de linha É o produto.
     */
    public function resolveVisitorHandle(PerformerProfile $profile, string $handle, int $limit = 10): ?int
    {
        $rows = $this->revealableVisitorRows($profile, $limit);

        if ($rows === null) {
            return null;
        }

        foreach ($rows as $row) {
            if (hash_equals($row['member_handle'], $handle)) {
                return $row['visitor_id'];
            }
        }

        return null;
    }

    /**
     * As linhas que o painel REVELARIA, com o `visitor_id` ainda dentro.
     *
     * Null quando o painel está travado (qualquer um dos dois pisos), que é
     * diferente de array vazio — vazio é "destravado, mas nenhuma faixa reuniu
     * k aliases". Os dois casos escondem tudo; a distinção existe porque
     * `visible` é decidido só pelos pisos (ver panelFor).
     *
     * @return array<int,array{visitor_id:int,fan:string,member_handle:string,lifestyle:?string,visited_slot:string}>|null
     */
    private function revealableVisitorRows(PerformerProfile $profile, int $limit): ?array
    {
        $floor = $this->visibility->floor();

        // Invariante: painel VISÍVEL nunca mostra menos gente que o piso.
        //
        // O piso é contado sobre a janela inteira (floorEligibleVisitorCount),
        // mas a lista sai cortada em $limit. Com $limit abaixo do piso os dois
        // números se descolam: 5 visitantes elegíveis destravam o painel e a
        // tela renderiza 3 aliases — um painel aberto exibindo menos nomes do
        // que o piso exige, que é exatamente o que o piso existe para impedir.
        //
        // Erro do CHAMADOR, não do usuário: nenhum request alcança isto, só um
        // `panelFor($profile, 3)` escrito à mão. Por isso LogicException e não
        // um clamp silencioso — clampar esconderia a decisão errada em vez de
        // apontá-la, e ela apareceria como "a tela mostra menos gente do que eu
        // pedi". Quebra em teste e em staging, antes de virar produção.
        //
        // O piso vem do FollowerVisibilityService (config `interest.anonymity_floor`,
        // com override por env), nunca de uma constante local: uma cópia do
        // número aqui passaria a discordar do piso real no dia em que a env
        // fosse setada, e discordaria justo no sentido permissivo.
        if ($limit < $floor) {
            throw new \LogicException(
                "ProfileVisitService::panelFor: limit ({$limit}) abaixo do Piso de Anonimato ({$floor}) — "
                .'o painel exibiria menos visitantes do que o piso exige.'
            );
        }

        if (! $this->visibility->canRevealList($profile->id)) {
            return null;
        }

        if ($this->floorEligibleVisitorCount($profile) < $floor) {
            return null;
        }

        $rows = $this->distinctVisitors($profile, $limit);

        // Estilo de Vida (Sprint 10). Resolvido em LOTE, aqui e não na linha:
        // uma chamada por visitante seria N+1 num painel que já paga duas
        // contagens de piso.
        //
        // A faixa é atributo do MEMBRO, não da visita: ela é a mesma para toda
        // performer, enquanto o `fan` ao lado muda de perfil para perfil. É uma
        // chave de join fraca entre listas de performers diferentes — a
        // ressalva está escrita em App\Support\LifestyleTier, e é por ela que o
        // campo é opcional e nunca vira filtro. Aqui o efeito é menor do que
        // parece: o k-anonimato por faixa já exige SLOT_MIN_K aliases para a
        // faixa horária aparecer, e a faixa de estilo de vida não muda quem
        // entra na lista — só o que se lê ao lado de quem já está nela.
        $lifestyleLabels = LifestyleTier::labelsFor(
            array_map(fn (object $row) => (int) $row->visitor_id, $rows)
        );

        return $this->revealableSlots(array_map(fn (object $row) => [
            'visitor_id' => (int) $row->visitor_id,
            // `null` quando o membro não declarou — a tela não desenha nada, e
            // não há placeholder: "não informou" diria à performer que aquele
            // visitante viu o formulário e recusou (item 14, mesma lógica).
            'lifestyle' => $lifestyleLabels[(int) $row->visitor_id] ?? null,
            // Mesmo pseudônimo por par (perfil, membro) das gorjetas e da
            // lista de seguidores: a performer reconhece "o Fã #0042 de
            // sempre" entre as telas, sem que o id cru saia daqui.
            'fan' => FanAlias::label($profile->id, (int) $row->visitor_id),
            // 16 hex: é IDENTIFICAÇÃO, e o `fan` acima não serve porque colide.
            'member_handle' => FanAlias::handle($profile->id, (int) $row->visitor_id),
            'visited_slot' => $this->slot(Carbon::parse($row->visited_at)),
        ], $rows));
    }

    /**
     * Faixa do dia em vez de relógio.
     *
     * `d/m/Y H:i` transformava o painel num oráculo de identidade: a performer
     * manda o link do perfil para UMA pessoa às 14:31, vê um alias novo
     * carimbado 14:32 e acabou de ligar aquele pseudônimo a um nome. Como o
     * FanAlias é estável por par, o vínculo vai junto para as gorjetas e para a
     * lista de seguidores — a correlação que o FanAlias existe para impedir,
     * reconstruída pelo relógio.
     *
     * Faixa de 6h é grossa o bastante para não casar com um envio pontual e
     * ainda responder o que a tela se propõe a responder ("teve movimento hoje
     * de manhã"). Fora do dia corrente sai só a data: nada obriga a ser mais
     * fino, e menos é mais seguro.
     */
    private function slot(Carbon $dt): string
    {
        $dt = $dt->copy()->setTimezone(self::DISPLAY_TIMEZONE);

        if (! $dt->isToday()) {
            return $dt->format('d/m/Y');
        }

        return match (true) {
            $dt->hour < 6 => 'Madrugada',
            $dt->hour < 12 => 'Manhã',
            $dt->hour < 18 => 'Tarde',
            default => 'Noite',
        };
    }

    /**
     * Aplica k-anonimato por faixa e embaralha dentro de cada uma.
     *
     * Duas defesas, contra ataques diferentes:
     *
     *  - SHUFFLE, contra a leitura passiva. A lista vem do banco ordenada por
     *    recência, então a primeira linha da faixa era o visitante mais recente
     *    dela: a POSIÇÃO entregava o que o relógio entregava antes do A1.
     *  - k, contra o REFRESH. A faixa só aparece já com k aliases dentro, então
     *    quem chegou entre dois refreshes é um entre k, não o único nome novo.
     *
     * Faixa incompleta some por inteiro, sem "aguardando" nem contador: um
     * placeholder dizendo "1 visita ainda oculta" reporia o sinal que o k tira —
     * a performer veria o contador subir no instante da visita.
     *
     * As faixas mantêm a ordem entre si (mais recente primeiro), que é a
     * informação que a tela legitimamente dá.
     *
     * @param  array<int,array<string,mixed>>  $visitors
     * @return array<int,array<string,mixed>>
     */
    private function revealableSlots(array $visitors): array
    {
        $grouped = [];

        // A ordem de PRIMEIRA aparição de cada faixa é a ordem de recência que
        // veio do banco, e o PHP preserva a ordem de inserção das chaves.
        foreach ($visitors as $visitor) {
            $grouped[$visitor['visited_slot']][] = $visitor;
        }

        $out = [];

        foreach ($grouped as $group) {
            if (count($group) < self::SLOT_MIN_K) {
                continue;
            }

            shuffle($group);

            foreach ($group as $visitor) {
                $out[] = $visitor;
            }
        }

        return $out;
    }

    /**
     * Quantos visitantes DISTINTOS e elegíveis ao piso na janela.
     *
     * Contado no banco e sobre a janela inteira, não sobre as `$limit` linhas já
     * paginadas de distinctVisitors(): o piso é sobre quantas pessoas passaram
     * por aqui, não sobre quantas cabem na tela.
     *
     * O critério de elegibilidade vem do FollowerVisibilityService, que é a dona
     * dele — reimplementá-lo aqui daria duas versões da mitigação de sybil para
     * manter em sincronia.
     */
    private function floorEligibleVisitorCount(PerformerProfile $profile): int
    {
        return ProfileVisit::where('performer_profile_id', $profile->id)
            ->where('visited_at', '>=', now()->subHours(self::RECENT_HOURS))
            ->whereHas('visitor', fn ($q) => $this->visibility->applyFloorEligibility(
                // Conta encerrada ou banida depois da visita não sustenta o
                // piso: ela não é mais uma pessoa diluindo a lista. Mesmo
                // `activeMember()` do piso de seguidores.
                $q->where('role', 'consumer')->where('status', 'active')
            ))
            ->distinct()
            ->count('visitor_id');
    }

    /**
     * Um membro, uma linha, com a visita mais recente. A lista responde "quem
     * passou por aqui hoje", não "quantas vezes cada um voltou" — a segunda
     * pergunta é sobre o comportamento do membro, e não é o que a tela se
     * propõe a mostrar.
     *
     * Agrupado no banco: `get()->unique()` traria a janela inteira para a
     * memória num perfil movimentado, para descartar quase tudo.
     *
     * @return array<int,object>
     */
    private function distinctVisitors(PerformerProfile $profile, int $limit): array
    {
        return ProfileVisit::where('performer_profile_id', $profile->id)
            ->where('visited_at', '>=', now()->subHours(self::RECENT_HOURS))
            ->groupBy('visitor_id')
            // `visited_at` tem precisão de SEGUNDO: dois membros que abrem o
            // perfil no mesmo segundo empatam, e sem desempate o MySQL devolve
            // a ordem que quiser — o painel trocaria de ordem entre dois F5.
            // O id cresce com a inserção, então MAX(id) é o desempate certo:
            // dentro do empate, quem chegou depois aparece antes. Só ordena,
            // nunca sai na resposta (ver panelFor).
            ->orderByDesc(DB::raw('MAX(visited_at)'))
            ->orderByDesc(DB::raw('MAX(id)'))
            ->limit($limit)
            ->get([DB::raw('visitor_id'), DB::raw('MAX(visited_at) as visited_at')])
            ->all();
    }

    /**
     * Apaga o histórico de visitas do titular.
     *
     * Chamado ao LIGAR o Ghost Mode — sem isso, o perk levaria até 24h para
     * fazer efeito e a performer continuaria vendo, no painel, alguém que
     * acabou de se tornar invisível. Também no encerramento de conta.
     */
    public function purgeFor(User $visitor): int
    {
        return ProfileVisit::where('visitor_id', $visitor->id)->delete();
    }

    /**
     * Expurgo por retenção — os DOIS sentidos (`visits:purge`). Ver RETENTION_DAYS.
     *
     * A mesma janela de 7 dias vale para membro→performer e para o sentido novo
     * performer→membro: nenhuma das listas tem finalidade que justifique guardar
     * o mapa de interesses além disso (LGPD, princípio da necessidade). Retorna o
     * total apagado — o command só loga a contagem.
     */
    public function purgeExpired(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        return ProfileVisit::where('visited_at', '<', $cutoff)->delete()
            + MemberProfileVisit::where('visited_at', '<', $cutoff)->delete();
    }
}
