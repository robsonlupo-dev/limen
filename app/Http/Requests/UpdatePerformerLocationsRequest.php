<?php

namespace App\Http\Requests;

use App\Models\PerformerProfile;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Salva a lista de localizações da performer (Sprint 13).
 *
 * Recebe `locations`: uma lista de `{state, city, is_primary}`. Lista vazia é
 * legítima — é o "Não compartilhar localização" (apaga tudo). As invariantes de
 * teto/primária/duplicata são reconferidas no PerformerLocationService sob lock;
 * aqui é a primeira barreira, que devolve 422 com erro de campo.
 *
 * PRIVACIDADE: é a PERFORMER editando a PRÓPRIA localização. `state` é fechado em
 * Rule::in(STATES) — vira dado público, não aceita texto livre. `city` é livre
 * (a lista de municípios não cabe numa constante) e justamente por isso não sai
 * em resource nenhum.
 */
class UpdatePerformerLocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `present` + `array`: a chave tem que vir (para lista vazia limpar de
            // propósito, não por payload malformado), mas pode ser [].
            'locations' => ['present', 'array', 'max:'.PerformerProfile::MAX_LOCATIONS],
            'locations.*.state' => ['required', Rule::in(PerformerProfile::STATES)],
            'locations.*.city' => ['nullable', 'string', 'max:100'],
            'locations.*.is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Duas regras que não cabem numa linha de `rules()` porque olham o conjunto:
     * exatamente uma primária (quando há alguma) e sem par estado+cidade repetido.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $locations = (array) $this->input('locations', []);

            if ($locations === []) {
                return;
            }

            $primaries = array_filter($locations, fn ($l) => filter_var($l['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN));

            if (count($primaries) !== 1) {
                $validator->errors()->add('locations', 'Marque exatamente uma localização como principal.');
            }

            $seen = [];
            foreach ($locations as $location) {
                $city = strtolower(trim((string) ($location['city'] ?? '')));
                $key = ($location['state'] ?? '').'|'.$city;

                if (isset($seen[$key])) {
                    $validator->errors()->add('locations', 'Você repetiu uma localização (mesmo estado e cidade).');
                    break;
                }

                $seen[$key] = true;
            }
        });
    }

    /**
     * A lista normalizada para o service — `is_primary` como bool de verdade
     * (o checkbox/rádio chega como '1'/'true'/'on').
     *
     * @return array<int, array{state: string, city: ?string, is_primary: bool}>
     */
    public function locations(): array
    {
        return array_map(fn ($l) => [
            'state' => $l['state'],
            'city' => $l['city'] ?? null,
            'is_primary' => filter_var($l['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], array_values((array) $this->validated('locations', [])));
    }
}
