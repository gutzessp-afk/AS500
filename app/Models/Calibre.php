<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tabla de calibres de lámina: kg/m² y peso de la hoja 4×8. */
class Calibre extends Model
{
    protected $table = 'calibres';

    protected $primaryKey = 'calibre';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
