<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plg_shopify_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('key', 64);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plg_shopify_settings');
    }
};
