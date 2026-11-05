<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una fila del MAPA DE TRADUCCION entre el esquema legacy y el destino.
 *
 * La DIRECCION es legacy -> destino: `legacy_table`/`legacy_column` son los
 * nombres que escribe el terminal legacy, y `new_table`/`new_column` son los
 * nombres de aqui. `new_table` NULL significa "esta columna NO se
 * traslada".
 *
 * El mapa es DATO AUDITABLE, no codigo: se ajusta sembrando filas, sin
 * desplegar. Un `if ($tabla === 'selling_info')` en el codigo seria un
 * defecto (ADR-0006).
 */
class LegacyTranslationMap extends Model
{
    use HasFactory;

    /**
     * La tabla se llama en SINGULAR (`legacy_translation_map`), y Eloquent
     * pluralizaria el nombre del modelo: sin esta linea, el modelo buscaría
     * `legacy_translation_maps` y no encontraría el mapa.
     */
    protected $table = 'legacy_translation_map';

    protected $fillable = [
        'legacy_table',
        'legacy_column',
        'new_table',
        'new_column',
        'transform',
    ];

    protected function casts(): array
    {
        return [
            'legacy_table' => 'string',
            'legacy_column' => 'string',
            'new_table' => 'string',
            'new_column' => 'string',
            'transform' => 'string',
        ];
    }
}
