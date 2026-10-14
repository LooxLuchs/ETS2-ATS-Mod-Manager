<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Models;

use Illuminate\Database\Eloquent\Model;

class Preset extends Model
{
    protected $table = 'ext_ets2_ats_mod_manager_presets';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['metadata' => 'array', 'hashes' => 'array', 'revision' => 'integer', 'imported_at' => 'datetime'];
    }
}
