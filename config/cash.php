<?php

return [

    /*
     * El UMBRAL del DESCUADRE: el importe a partir del cual un descuadre de
     * caja EXIGE justificacion.
     *
     * REGLA que fija este parametro: *un descuadre por encima del umbral no se
     * cierra sin un motivo*. Un descuadre es una EXCEPCION, y una excepcion
     * sin explicacion no es una excepcion: es un error que nadie sabe
     * explicar despues.
     *
     * El defecto es `0.0100`: un céntimo. Con ese umbral, cualquier
     * descuadre que no sea cero pide su motivo, y una caja que CUADRA nunca
     * lo pide (el descuadre de cero no es un descuadre). El importe viaja como
     * TEXTO decimal: un `float` no es un dinero.
     *
     * Y POR QUE ES UN PARAMETRO Y NO UNA COLUMNA: es una DECISION DE
     * OPERACION, no de esquema. Cambia con la operacion de la tienda, no con
     * el modelo de datos.
     *
     * Y EL ALCANCE: hoy es GLOBAL, no por tienda. El esquema de la Fase 0
     * (`store_settings`) no tiene ninguna columna donde encaje un umbral de
     * caja, y anadir una seria cambiar el esquema de la Fase 0 por una
     * necesidad de la Fase 2. Cuando la Fase 0 admita una columna (o una
     * tabla de parametros por tienda), la lectura passara a resolver el
     * umbral por tienda, con este valor como el DEFECTO.
     */
    'difference_reason_threshold' => env('CASH_DIFFERENCE_REASON_THRESHOLD', '0.0100'),

];
