<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAnimeCommentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth('api')->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'has_spoiler' => ['nullable', 'boolean'],
            'parent_id' => ['nullable', 'integer', 'exists:anime_comments,id'],
            'reply_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
