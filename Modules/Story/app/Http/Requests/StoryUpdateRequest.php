<?php

namespace Modules\Story\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoryUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'cover' => 'nullable|file|mimes:jpeg,png,jpg,webp|max:2048',
            'video' => 'nullable|file|max:4096',
            'link' => 'nullable|url|max:500',
            'status' => 'sometimes|required|in:draft,published,archived'
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
