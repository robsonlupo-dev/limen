# MINUTA — Instrumento Particular de Cessão de Direitos de Propriedade Intelectual

> **Status:** minuta de trabalho para **revisão por advogado**. Itens entre `[colchetes]` são lacunas ou decisões a confirmar. O advogado deve conferir os fundamentos legais.
> **Função:** transferir à Sociedade (a constituir ou já constituída) a titularidade patrimonial dos ativos do Projeto Limen. Sem cessão escrita, **não há transferência automática** de direitos de software e obras por encomenda para a empresa, especialmente quando o código foi escrito **antes** da constituição da Sociedade.

## Fundamentos para o advogado conferir

- **Software:** Lei nº 9.609/1998 (arts. 2º e 4º): o software é protegido como obra literária (Lei nº 9.610/1998). Quando criado fora de vínculo empregatício, a titularidade permanece com o **autor** até haver cessão. A cessão deve ser **escrita**.
- **Direitos autorais:** Lei nº 9.610/1998 — cessão por escrito, interpretada **restritivamente** (art. 49); direitos morais **inalienáveis** (art. 27). Para software, aplicam-se só os direitos de paternidade e de integridade (art. 2º, § 1º, Lei 9.609).
- **Marca:** Lei nº 9.279/1996 — o direito nasce do **registro no INPI**; até o depósito há mera expectativa; **cessão de pedido de registro** é possível.
- **Domínios:** `.br` — registrados no nome de pessoa física/jurídica no Registro.br; a transferência de titularidade segue o procedimento do Registro.br.
- **Integralização por bens:** se a cessão for feita como parte do capital social, o art. 1.055, § 1º, do Código Civil prevê **responsabilidade solidária por 5 anos** quanto à exata estimação dos bens conferidos; é preciso **avaliar** o bem.

---

## Partes

**CEDENTE(S):**
- **ROBSON LUIZ LUPO RAMOS**, CPF `[•]`, qualificado no MoU ("**Autor 1**");
- `[BRUNO (sobrenome), CPF •]` ("**Autor 2**"), quanto a contribuições próprias (materiais comerciais, listas, textos, identidade, relacionamentos de recrutamento, etc.);
- `[Terceiros: freelancers, designers, desenvolvedores — cada um assina sua própria cessão ou é incluído como cedente.]`

**CESSIONÁRIA:** `[LIMEN ______ LTDA.]`, pessoa jurídica de direito privado, CNPJ `[•]` (ou "sociedade a constituir", com eficácia **condicionada à constituição** e ao CNPJ).

## Cláusula 1 — Objeto

1.1. Os Cedentes **cedem e transferem** à Cessionária, de forma **total, definitiva, irrevogável e irretratável**, todos os **direitos patrimoniais** de propriedade intelectual sobre os **Ativos** descritos no **Anexo I**, incluindo o direito de reproduzir, modificar, adaptar, traduzir, distribuir, licenciar, sublicenciar, explorar economicamente por qualquer meio e em qualquer território, por todo o prazo de proteção legal.

1.2. Incluem-se os **trabalhos derivados**, aprimoramentos, correções e versões futuras criadas pelos Cedentes **para o Projeto** durante a vigência do vínculo com a Cessionária, que passam à Cessionária **automaticamente**, por força desta cláusula e sem novo instrumento.

1.3. Os **direitos morais** (paternidade e integridade) permanecem com os autores, nos limites da lei, e os Cedentes autorizam a Cessionária a usar e modificar os Ativos **sem menção de autoria** e sem que isso configure violação.

## Cláusula 2 — Ativos (resumo; detalhar no Anexo I)

| Categoria | Descrição | Observações |
|---|---|---|
| **Código-fonte** | Repositório `robsonlupo-dev/limen` (backend PHP/Laravel, frontend Vue/Inertia, migrations, testes, scripts, documentação técnica); infraestrutura como código | Autor: Robson. Verificar commits de terceiros e **dependências** com licenças. |
| **Banco de dados e esquemas** | Estrutura de dados, ledger de tokens, regras de economia | Dados pessoais **não** são cedidos como "ativo" sem base legal — ver cláusula 6 |
| **Marcas e sinais** | "Limen", "O Portal", "Cruze o limiar", logotipos, identidade visual | Sem registro no INPI até o momento `[confirmar]`; ceder também o direito de depositar |
| **Nomes de domínio** | `thelimen.com.br`, `limen.dev.br` | Transferência no Registro.br para a titularidade da Cessionária |
| **Contas e perfis** | `@limenoficial` (Instagram, X, TikTok, Telegram, Reddit), e-mail de administração, painéis de fornecedores | Dependem dos termos de cada rede; mudar o titular/administrador |
| **Conteúdo de marca** | Textos, imagens e vídeos de ambiente, vídeos da landing | **Atenção:** obras geradas por IA — a proteção autoral é incerta; manter registro de *prompts*, ferramenta e licença de uso comercial |
| **Fontes e assets de terceiros** | Cinzel, Manrope e outros | Licenças (OFL etc.): preservar |
| **Base de contatos / CRM** | Cadastro interno de Performers prospectadas (`limen_crm.db`) | **Dados pessoais** — sucessão do controlador, base legal e opt-out; ver cláusula 6 |
| **Documentação** | Especificações, runbooks, pareceres | Pareceres jurídicos são **confidenciais** |
| **Material de captação** | Roteiros de abordagem, modelos de mensagem, planilhas | Autor: Bruno e Robson |

## Cláusula 3 — Contrapartida

3.1. A cessão é feita como **contribuição ao Projeto**, em contrapartida à participação societária prevista no Contrato Social, e por **valor simbólico de R$ 1,00**, já recebido `[ou: "integralização de capital, com valor atribuído de R$ •, conforme laudo de avaliação"]`. `[O advogado deve decidir entre cessão onerosa simbólica e integralização com bens, e avaliar o impacto tributário e a responsabilidade do art. 1.055 § 1º do CC.]`

## Cláusula 4 — Garantias dos Cedentes

Cada Cedente declara que, em relação ao que cedeu:
- é o **autor ou titular legítimo** dos direitos;
- **não violou** direitos de terceiros (autorais, marcas, patentes, segredos) nem licenças de código aberto;
- **não cedeu, licenciou exclusivamente ou onerou** os Ativos a terceiros;
- **não incluiu** código ou informações **confidenciais de empregador ou de terceiros**, nem usou seus **recursos, equipamentos ou horário de trabalho** para o Projeto; e **revisou** seus contratos com terceiros (inclusive contrato de trabalho) quanto a **exclusividade, propriedade intelectual e conflito de interesse** `[ver nota abaixo]`;
- indicará, no Anexo II, **eventual código ou ativo reaproveitado de projeto anterior** (`[ex.: LiveCamBR]`), com o regime de titularidade dele.

## Cláusula 5 — Obrigações de colaboração

Os Cedentes assinarão os documentos adicionais necessários à formalização perante **INPI, Registro.br, GitHub, provedores de nuvem e redes sociais**, e **não contestarão** a titularidade da Cessionária.

## Cláusula 6 — Dados pessoais

6.1. A cessão de **bases de dados com dados pessoais** (como o cadastro de Performers prospectadas e a lista de espera) **não** equivale a transferir o direito de usá-los livremente. A Cessionária assume a condição de **controladora**, deve observar a LGPD, a finalidade original e o direito de oposição/eliminação dos titulares, e **não** pode ceder a terceiros.

## Cláusula 7 — Vigência, lei e foro

7.1. Eficácia a partir da **constituição da Cessionária e da emissão do CNPJ**. Se a Cessionária não for constituída até `[31/12/2027]`, a cessão **não produz efeitos**, e os Ativos permanecem em regime do MoU. 7.2. Lei brasileira. 7.3. Foro/arbitragem conforme o MoU.

Assinaturas (eletrônicas, com duas testemunhas): `[Cedentes] / [Cessionária] / [Testemunhas]`

---

## Anexo I — Inventário de ativos (preencher)

| # | Ativo | Identificador | Autor / titular atual | Data de criação | Observações / licença |
|---|---|---|---|---|---|
| 1 | Repositório de código | `github.com/robsonlupo-dev/limen` | Robson | `[•]` | |
| 2 | Domínio | `thelimen.com.br` | `[•]` | `[•]` | Registro.br |
| 3 | Domínio | `limen.dev.br` | `[•]` | `[•]` | |
| 4 | Marca | "Limen" | — | — | sem depósito no INPI |
| 5 | Contas de redes | `@limenoficial` | `[•]` | | admin.limen@gmail.com |
| 6 | CRM de Performers | base interna | Bruno / Robson | | LGPD |
| 7 | `[outros]` | | | | |

## Anexo II — Ativos de origem anterior ou terceiros

`[Listar: código ou ideias reaproveitados de projetos anteriores; bibliotecas relevantes; trabalho de freelancers; assets adquiridos; licenças.]`

---

### Alertas ao advogado

1. **Vínculo empregatício de um dos sócios.** O sócio que desenvolve o Projeto mantém emprego em outra empresa. É preciso conferir se o contrato de trabalho contém cláusula de **exclusividade**, **propriedade intelectual** sobre criações (inclusive fora do horário) ou **conflito de interesses**, e se houve uso de recursos do empregador. Isso define se há risco de reivindicação sobre o código. **Não estruturar o negócio para ocultar participação de empregador ou de terceiro.**
2. **Projeto anterior (LiveCamBR).** Verificar quem detém e o que foi reaproveitado.
3. **Obras com IA.** A proteção autoral de imagens e vídeos gerados por IA é incerta; a marca deve ser protegida como **sinal**, não só como obra.
4. **Marca.** Fazer **busca de anterioridade** antes de investir em identidade visual adicional; o depósito pode esperar, a busca não.
5. **Cadeia de titularidade do código.** Se houve outro desenvolvedor, designer ou ferramenta com termos próprios, incluí-lo.
