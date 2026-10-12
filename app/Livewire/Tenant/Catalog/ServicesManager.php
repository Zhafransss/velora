<?php

namespace App\Livewire\Tenant\Catalog;

use App\Models\CatalogService;

class ServicesManager extends ResourceManager
{
    protected function modelClass(): string
    {
        return CatalogService::class;
    }

    protected function title(): string
    {
        return 'Layanan';
    }

    protected function viewName(): string
    {
        return 'livewire.tenant.catalog.services';
    }

    protected function searchColumns(): array
    {
        return ['name', 'description'];
    }

    protected function defaults(): array
    {
        return [
            'name' => '', 'description' => '', 'price_from' => '', 'price_to' => '',
            'duration_minutes' => '', 'is_active' => true,
        ];
    }

    protected function formRules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:200'],
            'form.description' => ['nullable', 'string', 'max:2000'],
            'form.price_from' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'form.price_to' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'gte:form.price_from'],
            'form.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'form.is_active' => ['boolean'],
        ];
    }

    protected function normalize(array $data): array
    {
        foreach (['description', 'price_from', 'price_to', 'duration_minutes'] as $key) {
            $data[$key] = ($data[$key] === '' || $data[$key] === null) ? null : $data[$key];
        }

        return $data;
    }
}
