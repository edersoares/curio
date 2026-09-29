<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAuthorTable extends Migration
{
    public function up(): void
    {
        Schema::create('author', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->boolean('active')->default(true);
            $table->date('date_of_birth')->nullable();
            $table->integer('ranking')->nullable();
            $table->json('additional')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('author');
    }
}
