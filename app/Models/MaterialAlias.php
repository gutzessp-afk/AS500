<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cómo se llama el material en los planos ("LAMINA CRS C. 11") → material del catálogo. */
class MaterialAlias extends Model
{
    protected $table = 'material_alias';

    protected $primaryKey = 'alias';

    public $incrementing = false;

    protected $keyType = 'string';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $guarded = [];
}
