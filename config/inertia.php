<?php

/**
 * Inertia — donde se buscan los componentes de pagina.
 *
 * El default del paquete apunta a `resource_path('js/Pages')`, con P MAYUSCULA.
 * Este repo usa `resources/js/pages`, en minuscula.
 *
 * En Windows no se nota: el sistema de ficheros no distingue mayusculas de
 * minusculas, asi que `js/Pages` encuentra `js/pages`. En Linux SI se
 * distingue, y el finder no encuentra **ninguna** pagina: la aplicacion web
 * no renderiza nada y los tests de Inertia fallan con
 * "Inertia page component file [x] does not exist".
 *
 * Este fichero fija las rutas reales del repositorio, y lo hace con la
 * MINUSCULA con la que estan escritas.
 *
 * @see https://inertiajs.com/responses
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Rutas de los componentes
    |--------------------------------------------------------------------------
    |
    | Donde busca Inertia los ficheros de cada pagina. El renderer usa el
    | primer `page_paths` como el directorio de las vistas Blade, y el
    | finder de los tests usa el de `testing`.
    |
    */

    'ensure_pages_exist' => false,

    'page_paths' => [
        resource_path('js/pages'),
    ],

    'page_extensions' => [
        'js',
        'jsx',
        'svelte',
        'ts',
        'tsx',
        'vue',
    ],

    'use_script_element_for_initial_page' => (bool) env('INERTIA_USE_SCRIPT_ELEMENT_FOR_INITIAL_PAGE', false),

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | Mismas rutas, pero con la comprobacion activada: un test que asegura
    | una pagina que no existe, falla.
    |
    */

    'testing' => [
        'ensure_pages_exist' => true,

        'page_paths' => [
            resource_path('js/pages'),
        ],

        'page_extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],
    ],

];
