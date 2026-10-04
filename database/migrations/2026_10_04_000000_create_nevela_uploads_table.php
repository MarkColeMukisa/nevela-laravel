<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What Nevela knows about each uploaded file: where it is, how big it is, and for an
// image, its dimensions and the smaller renditions made from it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nevela_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 512)->unique();
            $table->string('disk');
            $table->string('resource')->index();
            $table->string('field');
            $table->string('name');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->json('renditions')->nullable();
            $table->string('profile')->nullable();
            $table->boolean('optimised')->default(false);
            $table->string('original_key', 512)->nullable();
            $table->unsignedBigInteger('original_size')->nullable();
            $table->string('uploaded_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_uploads');
    }
};
