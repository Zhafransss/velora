<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_package_features', function (Blueprint $table) {
             $table->id();

            $table->foreignId('package_id')
                ->constrained('service_packages')
                ->cascadeOnDelete();

            $table->string('text'); // fitur (semua aktif)
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['package_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_package_features');
    }
};
