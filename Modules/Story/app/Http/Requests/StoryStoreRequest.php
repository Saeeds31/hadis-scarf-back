<?php

namespace Modules\Story\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoryStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'cover' => 'required|file|mimes:jpeg,png,jpg,webp|max:2048',
            'video' => 'required|file|max:4096',
            'link' => 'required|url|max:500',
            'status' => 'required|in:draft,published,archived'
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
