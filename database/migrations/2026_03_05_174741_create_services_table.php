<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();

            // 1 kategori = 1 service (enak untuk CRUD 1 halaman)
            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete()
                ->unique();

            $table->text('hero_description')->nullable();
            $table->string('hero_image')->nullable(); // path storage, contoh: services/xxx.png

            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
