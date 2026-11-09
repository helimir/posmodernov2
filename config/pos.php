<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plan de cuentas de la caja
    |--------------------------------------------------------------------------
    |
    | El mapa de ROL a cuenta contable que necesita el checkout: `revenue`,
    | `tax_payable`, `cash` y `receivable`. Las cuentas son DATO, no codigo:
    | el dominio no inventa un plan de cuentas y las recibe en la entrada
    | (`CheckoutInput::$accounts`).
    |
    | Esto es CONFIGURACION, no dato de negocio: el repositorio todavia no
    | tiene donde CONFIGURAR un plan de cuentas por tienda (no hay tabla de
    | plan de cuentas), asi que vive en un fichero de configuracion. Cuando
    | exista la tabla, esta seccion deja de leerse y el plan sale de la fila
    | de la tienda.
    |
    | La clave `default` es el plan que se usa cuando la tienda no declara uno
    | propio. Las demas claves son identificadores de tienda: una tienda con
    | plan propio lo declara aqui y su plan gana al `default`.
    |
    */

    'accounts' => [
        'default' => [
            'revenue' => '4010000',
            'tax_payable' => '2110000',
            'cash' => '1100000',
            'receivable' => '1200000',
        ],

        // Por tienda:
        // '12' => [
        //     'revenue' => '4010000',
        //     'tax_payable' => '2110000',
        //     'cash' => '1100000',
        //     'receivable' => '1200000',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tamano de pagina del catalogo y de la busqueda
    |--------------------------------------------------------------------------
    |
    | Cuantos productos trae la pantalla de la caja de golpe, y cuantos trae
    | cada pagina de la busqueda. Son topes de lectura, no paginacion de
    | negocio: la caja carga un catalogo que se ve de un vistazo y busca por
    | paginas cortas.
    |
    */

    'catalog_page_size' => 24,

    'search_page_size' => 24,

];
