<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Isolated, non-web-served disk for KYC identity documents (principle 4:
        // PII isolated + encrypted at rest). No 'serve'/'url' — files are only
        // reachable server-side, decrypted through KycDocumentStore behind authz.
        'kyc' => [
            'driver' => 'local',
            'root' => storage_path('app/kyc'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // Fotos efêmeras do membro (docs/SECURITY_ISSUES.md § 1.7 e § 1.9).
        // Disco PRÓPRIO, e as duas razões são independentes:
        //
        //  1. Fora de `storage/app/private` e de `storage/app/kyc` porque o
        //     `docs/backup.sh` tarballa esses dois por diretório com
        //     RETENTION_DAYS=14 — uma foto com TTL de 24h que caísse ali
        //     sobreviveria duas semanas no backup, e "expira em 24h" viraria
        //     falso na primeira noite. Nenhuma exclusão no repo salvaria: o tar
        //     captura o diretório inteiro.
        //  2. Nunca o disco `kyc`, que o DeletionService trata como "destruir no
        //     encerramento": misturar as duas retenções confundiria os dois lados.
        //
        // `serve => false` como o `kyc`: os bytes só saem por MemberPhotoStore,
        // decifrados atrás de autorização — nunca por URL.
        // `throw => false` como o resto dos discos — o `retrieve()` do Store
        // depende de `get()` devolver null em vez de estourar. A contrapartida
        // é que put/delete devolvem `false` em silêncio quando falham, e por
        // isso o MemberPhotoStore CONFERE o retorno dos dois e lança ele mesmo.
        // Não troque para `throw => true` sem revisar `MemberPhotoStore::retrieve()`.
        'member_photos' => [
            'driver' => 'local',
            'root' => storage_path('app/member-photos'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // Stories da performer (docs/SECURITY_ISSUES.md § 2.3 e § 2.5).
        //
        // Disco PRÓPRIO, pelas mesmas duas razões do `member_photos` e por uma
        // terceira que é só daqui:
        //
        //  1. Fora de `storage/app/private` e de `storage/app/kyc` porque o
        //     `docs/backup.sh` tarballa esses dois por diretório com
        //     RETENTION_DAYS=14 — um story com prazo de 24h que caísse ali
        //     sobreviveria duas semanas no backup, e "expira em 24h" viraria
        //     falso na primeira noite. Nenhuma exclusão no repo salvaria: o tar
        //     captura o diretório inteiro. Hoje isso vale porque aquele script é
        //     ALLOWLIST — quem o converter para denylist reintroduz o problema em
        //     silêncio, nas duas features.
        //  2. Nunca o disco `kyc`, que o DeletionService trata como "destruir no
        //     encerramento": misturar as retenções confundiria os dois lados.
        //  3. Nunca o mesmo disco da foto efêmera do membro: lá o conteúdo é
        //     ciphertext e aqui é imagem em claro (§ 2.5, decisão de storage). Um
        //     disco só faria a próxima pessoa presumir a cifra errada dos dois
        //     lados — e a que erra presumindo cifra abre um caminho de serving sem
        //     authz por request, que é o § 2.3.
        //
        // `serve => false` como o `kyc` e o `member_photos`: os bytes só saem pelo
        // PerformerStoryStore, atrás de autorização reavaliada A CADA request.
        // **Nada de URL assinada** — assinatura não amarra viewer nenhum, e a URL
        // do membro Black seria um bearer token colável no WhatsApp que evapora o
        // paywall do Nível 3 (§ 2.3).
        //
        // `throw => false` como o resto dos discos — o `retrieve()` do Store
        // depende de `get()` devolver null em vez de estourar. A contrapartida é
        // que put/delete devolvem `false` em silêncio quando falham, e por isso o
        // PerformerStoryStore CONFERE o retorno dos dois e lança ele mesmo.
        'performer_stories' => [
            'driver' => 'local',
            'root' => storage_path('app/performer-stories'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
