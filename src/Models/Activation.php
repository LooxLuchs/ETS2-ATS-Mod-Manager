<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Models;

use Illuminate\Database\Eloquent\Model;

class Activation extends Model
{
    protected $table = 'ext_ets2_ats_mod_manager_activations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
