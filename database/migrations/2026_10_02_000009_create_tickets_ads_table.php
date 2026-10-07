<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->nullable()->constrained('tickets_events')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('image_path');
            $table->string('target_url', 500)->nullable();
            $table->enum('position', ['top', 'banner', 'sidebar', 'modal'])->default('banner');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('enable')->default(true);
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->timestamps();

            $table->index(['enable', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_ads');
    }
};