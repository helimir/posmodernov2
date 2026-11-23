<?php

/*
|--------------------------------------------------------------------------
| Los BACKUPS
|--------------------------------------------------------------------------
|
| Un backup es un fichero, y ese fichero solo vale si ALGUIEN lo puede
| restaurar. Por eso la configuracion declara de que forma se habla con las
| herramientas de PostgreSQL:
|
|  - `driver` = `pg_dump`: las herramientas estan en el PATH del host. Es el
|    caso del servidor de produccion y el de la CI, donde el cliente de
|    PostgreSQL esta instalado.
|  - `driver` = `docker`: las herramientas estan DENTRO del contenedor de la
|    base. Es el caso de un desarrollo con la base en Docker, donde el host
|    no tiene ni `pg_dump` ni `psql`.
|  - `driver` = `auto`: se usa `pg_dump` si esta en el PATH, y si no, Docker.
|
| Y EL MISM0 CODIGO SIRVE PARA LOS DOS. Un backup que solo funciona en una
| maquina es un backup que no existe el dia de la recuperacion.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | La CARPETA de los backups
    |----------------------------------------------------------------------
    |
    | Fuera de `public/`: un backup no se sirve, se guarda. Y con la
    | retencion de mas abajo, la carpeta no crece sin limite.
    |
    */

    'path' => env('BACKUP_PATH', storage_path('backups')),

    /*
    |----------------------------------------------------------------------
    | El DRIVER: `auto`, `pg_dump` o `docker`
    |----------------------------------------------------------------------
    */

    'driver' => env('BACKUP_DRIVER', 'auto'),

    /*
    |----------------------------------------------------------------------
    | El CONTENEDOR de Docker, cuando el driver es `docker`
    |----------------------------------------------------------------------
    |
    | El nombre del contenedor de PostgreSQL. Y la imagen, para cuando el
    | contenedor no exista todavia: `docker` puede levantar uno efimero.
    |
    */

    'docker_container' => env('BACKUP_DOCKER_CONTAINER', 'pos-moderno-v2-postgres-1'),

    'docker_image' => env('BACKUP_DOCKER_IMAGE', 'postgres:17-alpine'),

    /*
    |----------------------------------------------------------------------
    | Los BINARIOS
    |----------------------------------------------------------------------
    */

    'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),

    'pg_restore' => env('BACKUP_PG_RESTORE', 'pg_restore'),

    /*
    |----------------------------------------------------------------------
    | La RETENCION de los backups
    |----------------------------------------------------------------------
    |
    | Cuantos dias se guarda un backup. Un backup de hace un ano no protege
    | de la duda de hoy; y uno de hace tres dias, si.
    |
    */

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    /*
    |----------------------------------------------------------------------
    | El SUELO de la retencion
    |----------------------------------------------------------------------
    |
    | Por debajo de este numero de dias, la limpieza NO borra. Un backup es
    | la ultima linea de defensa: si el reloj va mal, o el valor esta mal
    | escrito, el primer backup que se lleva por delante es el unico que
    | hay.
    |
    */

    'retention_floor_days' => (int) env('BACKUP_RETENTION_FLOOR_DAYS', 7),

];
