<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 100)->nullable();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->decimal('price', 14, 2)->nullable();
            $table->char('currency', 3)->default('IDR');
            // NULL = stok tidak diketahui (bukan 0). Bot tidak boleh menjawab stok bila NULL.
            $table->decimal('stock_quantity', 12, 3)->nullable();
            $table->string('stock_status', 20)->default('unknown'); // in_stock | out_of_stock | unknown
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
            // SKU boleh kosong; bila terisi unik per tenant (baris terhapus lunak tetap memegang SKU-nya).
            $table->unique(['tenant_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
