<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacy = [
            'ext_'.'scs'.'_mod_manager_activations' => 'ext_ets2_ats_mod_manager_activations',
            'ext_'.'scs'.'_mod_manager_presets' => 'ext_ets2_ats_mod_manager_presets',
            'ext_'.'scs'.'_mod_manager_operations' => 'ext_ets2_ats_mod_manager_operations',
        ];
        foreach ($legacy as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }

        if (! Schema::hasTable('ext_ets2_ats_mod_manager_activations')) {
            Schema::create('ext_ets2_ats_mod_manager_activations', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('egg_id')->unique();
                $table->enum('game', ['ets2', 'ats']);
                $table->string('path_template', 2048);
                $table->boolean('enabled')->default(true);
                $table->timestamps();
                $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('ext_ets2_ats_mod_manager_presets')) {
            Schema::create('ext_ets2_ats_mod_manager_presets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedInteger('server_id')->index();
                $table->string('name', 100);
                $table->string('game', 4);
                $table->unsignedInteger('revision')->default(1);
                $table->json('metadata');
                $table->json('hashes');
                $table->timestamp('imported_at');
                $table->timestamps();
                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('ext_ets2_ats_mod_manager_operations')) {
            Schema::create('ext_ets2_ats_mod_manager_operations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedInteger('server_id')->index();
                $table->uuid('preset_id')->nullable()->index();
                $table->string('kind', 20);
                $table->string('status', 32)->index();
                $table->json('journal');
                $table->timestamps();
                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_ets2_ats_mod_manager_operations');
        Schema::dropIfExists('ext_ets2_ats_mod_manager_presets');
        Schema::dropIfExists('ext_ets2_ats_mod_manager_activations');
    }
};
