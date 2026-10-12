<?php

namespace App\Livewire\Tenant\Catalog;

use App\Models\Product;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Validation\Rule;

class ProductsManager extends ResourceManager
{
    protected function modelClass(): string
    {
        return Product::class;
    }

    protected function title(): string
    {
        return 'Produk';
    }

    protected function viewName(): string
    {
        return 'livewire.tenant.catalog.products';
    }

    protected function searchColumns(): array
    {
        return ['name', 'sku', 'description'];
    }

    protected function defaults(): array
    {
        return [
            'name' => '', 'sku' => '', 'description' => '', 'price' => '',
            'stock_quantity' => '', 'stock_status' => Product::STOCK_UNKNOWN, 'is_active' => true,
        ];
    }

    protected function formRules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'form.name' => ['required', 'string', 'max:200'],
            'form.sku' => [
                'nullable', 'string', 'max:100',
                // Unik per tenant (termasuk yang terhapus lunak, sesuai indeks DB).
                Rule::unique('products', 'sku')->where('tenant_id', $tenantId)->ignore($this->editingId),
            ],
            'form.description' => ['nullable', 'string', 'max:2000'],
            'form.price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'form.stock_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'form.stock_status' => ['required', Rule::in(Product::STOCK_STATUSES)],
            'form.is_active' => ['boolean'],
        ];
    }

    protected function normalize(array $data): array
    {
        // Kolom kosong = NULL. Khusus stok, NULL berarti "tidak diketahui", bukan 0.
        foreach (['sku', 'description', 'price', 'stock_quantity'] as $key) {
            $data[$key] = ($data[$key] === '' || $data[$key] === null) ? null : $data[$key];
        }

        return $data;
    }
}
