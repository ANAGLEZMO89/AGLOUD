<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Notificaciones traídas de la DEHú (LEMA): identificador del envío y sus metadatos públicos */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificaciones', function (Blueprint $t) {
            $t->string('dehu_id', 120)->nullable()->after('referencia');
            $t->text('dehu')->nullable()->after('dehu_id');
            $t->index(['cliente_id', 'dehu_id']);
        });
    }

    public function down(): void
    {
        Schema::table('notificaciones', function (Blueprint $t) {
            $t->dropIndex(['cliente_id', 'dehu_id']);
            $t->dropColumn(['dehu_id', 'dehu']);
        });
    }
};
