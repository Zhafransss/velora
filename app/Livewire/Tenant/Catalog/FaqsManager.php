<?php

namespace App\Livewire\Tenant\Catalog;

use App\Models\Faq;

class FaqsManager extends ResourceManager
{
    protected function modelClass(): string
    {
        return Faq::class;
    }

    protected function title(): string
    {
        return 'FAQ';
    }

    protected function viewName(): string
    {
        return 'livewire.tenant.catalog.faqs';
    }

    protected function searchColumns(): array
    {
        return ['question', 'answer', 'category'];
    }

    protected function defaults(): array
    {
        return ['question' => '', 'answer' => '', 'category' => '', 'is_active' => true];
    }

    protected function formRules(): array
    {
        return [
            'form.question' => ['required', 'string', 'max:1000'],
            'form.answer' => ['required', 'string', 'max:5000'],
            'form.category' => ['nullable', 'string', 'max:100'],
            'form.is_active' => ['boolean'],
        ];
    }

    protected function normalize(array $data): array
    {
        $data['category'] = $data['category'] === '' ? null : $data['category'];

        return $data;
    }
}
