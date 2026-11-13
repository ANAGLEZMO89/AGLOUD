<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ajustes de la aplicación (valores cifrados): token de Google Drive, etc.
        Schema::create('ajustes', function (Blueprint $table) {
            $table->string('clave', 80)->primary();
            $table->text('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes');
    }
};
