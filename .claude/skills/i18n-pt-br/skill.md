# Skill: i18n-pt-br

## Configuração obrigatória em config/app.php
```php
'locale' => 'pt_BR',
'fallback_locale' => 'pt_BR',
'faker_locale' => 'pt_BR',
```

## Estrutura de arquivos de idioma
```
lang/
  pt_BR/
    auth.php
    pagination.php
    passwords.php
    validation.php
```

## lang/pt_BR/auth.php
```php
<?php
return [
    'failed'   => 'Credenciais incorretas.',
    'password' => 'A senha informada está incorreta.',
    'throttle' => 'Muitas tentativas de login. Tente novamente em :seconds segundos.',
];
```

## lang/pt_BR/pagination.php
```php
<?php
return [
    'previous' => '&laquo; Anterior',
    'next'     => 'Próximo &raquo;',
];
```

## lang/pt_BR/passwords.php
```php
<?php
return [
    'reset'     => 'Sua senha foi redefinida!',
    'sent'      => 'Enviamos um link de redefinição de senha para seu e-mail!',
    'throttled' => 'Aguarde antes de tentar novamente.',
    'token'     => 'Este token de redefinição de senha é inválido.',
    'user'      => 'Não encontramos um usuário com esse endereço de e-mail.',
];
```

## lang/pt_BR/validation.php (completo)
```php
<?php
return [
    'accepted'             => 'O campo :attribute deve ser aceito.',
    'accepted_if'          => 'O campo :attribute deve ser aceito quando :other é :value.',
    'active_url'           => 'O campo :attribute deve ser uma URL válida.',
    'after'                => 'O campo :attribute deve ser uma data posterior a :date.',
    'after_or_equal'       => 'O campo :attribute deve ser uma data posterior ou igual a :date.',
    'alpha'                => 'O campo :attribute deve conter apenas letras.',
    'alpha_dash'           => 'O campo :attribute deve conter apenas letras, números, traços e underscores.',
    'alpha_num'            => 'O campo :attribute deve conter apenas letras e números.',
    'array'                => 'O campo :attribute deve ser um array.',
    'before'               => 'O campo :attribute deve ser uma data anterior a :date.',
    'before_or_equal'      => 'O campo :attribute deve ser uma data anterior ou igual a :date.',
    'between'              => [
        'array'   => 'O campo :attribute deve ter entre :min e :max itens.',
        'file'    => 'O campo :attribute deve ter entre :min e :max kilobytes.',
        'numeric' => 'O campo :attribute deve estar entre :min e :max.',
        'string'  => 'O campo :attribute deve ter entre :min e :max caracteres.',
    ],
    'boolean'              => 'O campo :attribute deve ser verdadeiro ou falso.',
    'confirmed'            => 'A confirmação do campo :attribute não coincide.',
    'current_password'     => 'A senha está incorreta.',
    'date'                 => 'O campo :attribute deve ser uma data válida.',
    'date_equals'          => 'O campo :attribute deve ser uma data igual a :date.',
    'date_format'          => 'O campo :attribute deve estar no formato :format.',
    'declined'             => 'O campo :attribute deve ser recusado.',
    'different'            => 'Os campos :attribute e :other devem ser diferentes.',
    'digits'               => 'O campo :attribute deve ter :digits dígitos.',
    'digits_between'       => 'O campo :attribute deve ter entre :min e :max dígitos.',
    'dimensions'           => 'O campo :attribute contém dimensões de imagem inválidas.',
    'distinct'             => 'O campo :attribute contém um valor duplicado.',
    'doesnt_start_with'    => 'O campo :attribute não deve começar com: :values.',
    'email'                => 'O campo :attribute deve ser um endereço de e-mail válido.',
    'ends_with'            => 'O campo :attribute deve terminar com um dos seguintes valores: :values.',
    'enum'                 => 'O valor selecionado para :attribute é inválido.',
    'exists'               => 'O valor selecionado para :attribute é inválido.',
    'file'                 => 'O campo :attribute deve ser um arquivo.',
    'filled'               => 'O campo :attribute deve ter um valor.',
    'gt'                   => [
        'array'   => 'O campo :attribute deve ter mais de :value itens.',
        'file'    => 'O campo :attribute deve ser maior que :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser maior que :value.',
        'string'  => 'O campo :attribute deve ter mais de :value caracteres.',
    ],
    'gte'                  => [
        'array'   => 'O campo :attribute deve ter :value itens ou mais.',
        'file'    => 'O campo :attribute deve ser maior ou igual a :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser maior ou igual a :value.',
        'string'  => 'O campo :attribute deve ter :value caracteres ou mais.',
    ],
    'image'                => 'O campo :attribute deve ser uma imagem.',
    'in'                   => 'O valor selecionado para :attribute é inválido.',
    'in_array'             => 'O campo :attribute não existe em :other.',
    'integer'              => 'O campo :attribute deve ser um número inteiro.',
    'ip'                   => 'O campo :attribute deve ser um endereço IP válido.',
    'ipv4'                 => 'O campo :attribute deve ser um endereço IPv4 válido.',
    'ipv6'                 => 'O campo :attribute deve ser um endereço IPv6 válido.',
    'json'                 => 'O campo :attribute deve ser uma string JSON válida.',
    'lt'                   => [
        'array'   => 'O campo :attribute deve ter menos de :value itens.',
        'file'    => 'O campo :attribute deve ser menor que :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser menor que :value.',
        'string'  => 'O campo :attribute deve ter menos de :value caracteres.',
    ],
    'lte'                  => [
        'array'   => 'O campo :attribute não deve ter mais de :value itens.',
        'file'    => 'O campo :attribute deve ser menor ou igual a :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser menor ou igual a :value.',
        'string'  => 'O campo :attribute deve ter :value caracteres ou menos.',
    ],
    'mac_address'          => 'O campo :attribute deve ser um endereço MAC válido.',
    'max'                  => [
        'array'   => 'O campo :attribute não deve ter mais de :max itens.',
        'file'    => 'O campo :attribute não deve ser maior que :max kilobytes.',
        'numeric' => 'O campo :attribute não deve ser maior que :max.',
        'string'  => 'O campo :attribute não deve ter mais de :max caracteres.',
    ],
    'mimes'                => 'O campo :attribute deve ser um arquivo do tipo: :values.',
    'mimetypes'            => 'O campo :attribute deve ser um arquivo do tipo: :values.',
    'min'                  => [
        'array'   => 'O campo :attribute deve ter pelo menos :min itens.',
        'file'    => 'O campo :attribute deve ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute deve ser pelo menos :min.',
        'string'  => 'O campo :attribute deve ter pelo menos :min caracteres.',
    ],
    'multiple_of'          => 'O campo :attribute deve ser um múltiplo de :value.',
    'not_in'               => 'O valor selecionado para :attribute é inválido.',
    'not_regex'            => 'O formato do campo :attribute é inválido.',
    'numeric'              => 'O campo :attribute deve ser um número.',
    'password'             => [
        'letters'       => 'O campo :attribute deve conter pelo menos uma letra.',
        'mixed'         => 'O campo :attribute deve conter letras maiúsculas e minúsculas.',
        'numbers'       => 'O campo :attribute deve conter pelo menos um número.',
        'symbols'       => 'O campo :attribute deve conter pelo menos um símbolo.',
        'uncompromised' => 'O valor informado para :attribute apareceu em um vazamento de dados. Por favor, escolha outro :attribute.',
    ],
    'present'              => 'O campo :attribute deve estar presente.',
    'prohibited'           => 'O campo :attribute é proibido.',
    'prohibited_if'        => 'O campo :attribute é proibido quando :other é :value.',
    'prohibited_unless'    => 'O campo :attribute é proibido a não ser que :other esteja em :values.',
    'prohibits'            => 'O campo :attribute proíbe que :other esteja presente.',
    'regex'                => 'O formato do campo :attribute é inválido.',
    'required'             => 'O campo :attribute é obrigatório.',
    'required_array_keys'  => 'O campo :attribute deve conter entradas para: :values.',
    'required_if'          => 'O campo :attribute é obrigatório quando :other é :value.',
    'required_if_accepted' => 'O campo :attribute é obrigatório quando :other é aceito.',
    'required_unless'      => 'O campo :attribute é obrigatório, a menos que :other esteja em :values.',
    'required_with'        => 'O campo :attribute é obrigatório quando :values está presente.',
    'required_with_all'    => 'O campo :attribute é obrigatório quando :values estão presentes.',
    'required_without'     => 'O campo :attribute é obrigatório quando :values não está presente.',
    'required_without_all' => 'O campo :attribute é obrigatório quando nenhum dos :values está presente.',
    'same'                 => 'Os campos :attribute e :other devem ser iguais.',
    'size'                 => [
        'array'   => 'O campo :attribute deve conter :size itens.',
        'file'    => 'O campo :attribute deve ter :size kilobytes.',
        'numeric' => 'O campo :attribute deve ser :size.',
        'string'  => 'O campo :attribute deve ter :size caracteres.',
    ],
    'starts_with'          => 'O campo :attribute deve começar com um dos seguintes valores: :values.',
    'string'               => 'O campo :attribute deve ser uma string.',
    'timezone'             => 'O campo :attribute deve ser um fuso horário válido.',
    'unique'               => 'O valor informado para :attribute já está em uso.',
    'uploaded'             => 'O upload do campo :attribute falhou.',
    'url'                  => 'O campo :attribute deve ser uma URL válida.',
    'uuid'                 => 'O campo :attribute deve ser um UUID válido.',
    
    'attributes' => [
        'email'                 => 'e-mail',
        'password'              => 'senha',
        'password_confirmation' => 'confirmação de senha',
        'name'                  => 'nome',
        'cpf'                   => 'CPF',
        'phone'                 => 'telefone',
        'tokens'                => 'tokens',
        'pix_key'               => 'chave PIX',
        'pix_key_type'          => 'tipo de chave PIX',
        'amount'                => 'valor',
        'document'              => 'documento',
        'selfie'                => 'selfie',
        'slug'                  => 'identificador',
        'bio'                   => 'biografia',
        'avatar'                => 'foto de perfil',
        'cover'                 => 'foto de capa',
    ],
];
```

## Páginas de erro customizadas
Criar em `resources/views/errors/`:

### 404.blade.php
```blade
<x-guest-layout>
    <div class="min-h-screen bg-[#0A0A0B] flex items-center justify-center">
        <div class="text-center">
            <h1 class="text-6xl font-bold text-[#C9A24B] font-cormorant">404</h1>
            <p class="text-white text-xl mt-4">Página não encontrada</p>
            <a href="/" class="mt-6 inline-block text-[#C9A24B] hover:underline">
                Voltar ao início
            </a>
        </div>
    </div>
</x-guest-layout>
```

### 403.blade.php
```blade
<x-guest-layout>
    <div class="min-h-screen bg-[#0A0A0B] flex items-center justify-center">
        <div class="text-center">
            <h1 class="text-6xl font-bold text-[#C9A24B] font-cormorant">403</h1>
            <p class="text-white text-xl mt-4">Acesso negado</p>
            <a href="/" class="mt-6 inline-block text-[#C9A24B] hover:underline">
                Voltar ao início
            </a>
        </div>
    </div>
</x-guest-layout>
```

### 500.blade.php
```blade
<x-guest-layout>
    <div class="min-h-screen bg-[#0A0A0B] flex items-center justify-center">
        <div class="text-center">
            <h1 class="text-6xl font-bold text-[#C9A24B] font-cormorant">500</h1>
            <p class="text-white text-xl mt-4">Erro interno do servidor</p>
            <p class="text-gray-400 mt-2">Nossa equipe foi notificada.</p>
            <a href="/" class="mt-6 inline-block text-[#C9A24B] hover:underline">
                Voltar ao início
            </a>
        </div>
    </div>
</x-guest-layout>
```

### 419.blade.php
```blade
<x-guest-layout>
    <div class="min-h-screen bg-[#0A0A0B] flex items-center justify-center">
        <div class="text-center">
            <h1 class="text-6xl font-bold text-[#C9A24B] font-cormorant">419</h1>
            <p class="text-white text-xl mt-4">Sessão expirada</p>
            <p class="text-gray-400 mt-2">Por favor, recarregue a página e tente novamente.</p>
            <a href="/" class="mt-6 inline-block text-[#C9A24B] hover:underline">
                Recarregar
            </a>
        </div>
    </div>
</x-guest-layout>
```
