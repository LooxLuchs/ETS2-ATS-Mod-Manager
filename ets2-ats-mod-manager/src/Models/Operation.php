<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Models;

use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    public const PENDING = ['preparing', 'prepared', 'swapping', 'recovery_required'];

    protected $table = 'ext_ets2_ats_mod_manager_operations';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['journal' => 'array'];
    }
}
