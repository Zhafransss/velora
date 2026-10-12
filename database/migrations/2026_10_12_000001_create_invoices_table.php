<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->date('invoice_date');
            $table->string('customer_name');
            $table->string('business_name')->nullable();
            $table->text('address');
            $table->string('whatsapp', 30);
            // Line items: [{description, qty, price}] — disimpan JSON agar invoice tetap utuh walau harga layanan berubah.
            $table->json('items');
            $table->string('payment_method', 20);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('invoice_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
