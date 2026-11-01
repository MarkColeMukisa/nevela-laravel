<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The last number given to each numbered field (see Support\Numbering), so that records
// made at the same moment are each given their own.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nevela_numbers', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->unsignedBigInteger('last')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nevela_numbers');
    }
};
