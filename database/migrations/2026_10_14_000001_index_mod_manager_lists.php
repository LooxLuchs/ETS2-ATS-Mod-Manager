<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ext_ets2_ats_mod_manager_presets', function (Blueprint $table) {
            $table->index(['server_id', 'game', 'imported_at'], 'mm_presets_library');
        });
        Schema::table('ext_ets2_ats_mod_manager_operations', function (Blueprint $table) {
            $table->index(['server_id', 'status', 'kind', 'created_at'], 'mm_ops_status');
            $table->index(['server_id', 'preset_id', 'status', 'kind', 'created_at'], 'mm_ops_snapshots');
        });
    }

    public function down(): void
    {
        Schema::table('ext_ets2_ats_mod_manager_operations', function (Blueprint $table) {
            $table->dropIndex('mm_ops_status');
            $table->dropIndex('mm_ops_snapshots');
        });
        Schema::table('ext_ets2_ats_mod_manager_presets', function (Blueprint $table) {
            $table->dropIndex('mm_presets_library');
        });
    }
};
