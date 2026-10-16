<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DocumentAcceptance;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Textos jurídicos, em rota pública.
 *
 * Pública de propósito: a performer precisa conseguir LER o documento antes de
 * ter conta, e o link tem que continuar abrindo depois — um contrato que só o
 * signatário logado enxerga não serve como referência.
 *
 * O corpo de cada documento vive em `resources/legal/<arquivo>.md` (Markdown,
 * revisável por advogado em diff) e é renderizado aqui no servidor. É conteúdo
 * NOSSO, versionado no Git — nunca input de usuário. A versão exibida vem do
 * mesmo config que o aceite grava, então a página nunca mostra um texto rotulado
 * com versão diferente da que seria registrada.
 *
 * Os textos atuais são MINUTAS pré-lançamento (config `legal.draft` mostra a
 * faixa de aviso até a revisão por advogado).
 */
class LegalDocumentsController extends Controller
{
    /**
     * Catálogo dos documentos públicos: chave → título, arquivo .md, chave da
     * versão em config/documents.php e resumo (meta description).
     *
     * @var array<string, array{title: string, file: string, version: string, summary: string}>
     */
    private const DOCUMENTS = [
        'terms' => [
            'title' => 'Termos de Uso',
            'file' => 'termos-de-uso',
            'version' => 'terms',
            'summary' => 'Regras de uso da Limen para Membros: conta, tokens, funcionalidades pagas, conduta e privacidade.',
        ],
        'privacy' => [
            'title' => 'Política de Privacidade',
            'file' => 'politica-de-privacidade',
            'version' => 'privacy',
            'summary' => 'Como a Limen trata seus dados pessoais (LGPD): o que coleta, por quê, com quem compartilha e seus direitos.',
        ],
        'content_policy' => [
            'title' => 'Política de Conteúdo Proibido e Moderação',
            'file' => 'politica-de-conteudo',
            'version' => DocumentAcceptance::TYPE_CONTENT_POLICY,
            'summary' => 'O que não pode existir na Limen e como funcionam denúncias, moderação e cooperação com as autoridades.',
        ],
        'performance_contract' => [
            'title' => 'Contrato de Performer',
            'file' => 'contrato-de-performance',
            'version' => DocumentAcceptance::TYPE_PERFORMANCE_CONTRACT,
            'summary' => 'Termos de uso e contrato da Performer: autonomia, remuneração, conteúdo, privacidade e rescisão.',
        ],
        'age_verification' => [
            'title' => 'Verificação Etária e Proteção de Menores',
            'file' => 'verificacao-etaria',
            'version' => 'age_verification',
            'summary' => 'Como a Limen verifica a idade, protege crianças e adolescentes e como denunciar.',
        ],
    ];

    public function terms(): Response
    {
        return $this->render('terms');
    }

    public function privacy(): Response
    {
        return $this->render('privacy');
    }

    public function contentPolicy(): Response
    {
        return $this->render('content_policy');
    }

    public function performanceContract(): Response
    {
        return $this->render('performance_contract');
    }

    public function ageVerification(): Response
    {
        return $this->render('age_verification');
    }

    private function render(string $key): Response
    {
        $doc = self::DOCUMENTS[$key];

        $markdown = File::get(resource_path("legal/{$doc['file']}.md"));
        $markdown = str_replace('{{CONTATO}}', $this->contact(), $markdown);

        $html = Str::markdown($markdown, [
            // O .md é nosso, mas HTML cru e links "javascript:" ficam fora por
            // princípio: o corpo vai para v-html.
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return Inertia::render('Legal/Document', [
            'document' => [
                'key' => $key,
                'title' => $doc['title'],
                'version' => DocumentAcceptance::currentVersion($doc['version']),
                'html' => $html,
            ],
            'draft' => (bool) config('legal.draft'),
            'related' => $this->related(),
            'meta' => [
                'title' => $doc['title'].' — Limen',
                'description' => $doc['summary'],
            ],
        ]);
    }

    /**
     * E-mail do canal de privacidade/denúncias, já em Markdown. Sem
     * LEGAL_CONTACT_EMAIL configurado, o texto cai para uma frase neutra em vez
     * de exibir um endereço inventado.
     */
    private function contact(): string
    {
        $email = config('legal.contact_email');

        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return "[{$email}](mailto:{$email})";
        }

        return 'o canal de contato da plataforma';
    }

    /**
     * Navegação entre os documentos (rodapé da página). Ordem de exibição fixa.
     *
     * @return list<array{key: string, title: string}>
     */
    private function related(): array
    {
        return collect(self::DOCUMENTS)
            ->map(fn (array $doc, string $key) => ['key' => $key, 'title' => $doc['title']])
            ->values()
            ->all();
    }
}
