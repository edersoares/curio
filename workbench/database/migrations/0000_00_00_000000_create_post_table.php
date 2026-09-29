<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePostTable extends Migration
{
    public function up(): void
    {
        Schema::create('post', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id');
            $table->string('title');
            $table->text('content');
            $table->date('published_at')->nullable();
            $table->enum('status', ['draft', 'review', 'done', 'published'])->default('draft');
            $table->string('category');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post');
    }
}
