<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
            // Opcional: o ORIGINAL não-recortado (UAT rodada 6). O editor de capa
            // corta a 2:1 no cliente antes de enviar, então `file` é só a faixa; o
            // original vem aqui para a cópia INTEIRA do modal de ampliar. Só a capa
            // envia; avatar/outros ignoram. Limite maior (foto de celular crua).
            'file_full' => ['nullable', 'file', 'mimes:jpeg,png,webp', 'max:10240'],
        ];
    }
}
