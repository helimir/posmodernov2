<?php

use App\Domain\Fiscal\Enums\ProviderKind;
use App\Domain\Fiscal\FiscalProviderResolver;
use App\Fiscal\Enums\SeriesAuthority;
use App\Fiscal\Enums\SeriesRole;
use App\Fiscal\FiscalChannels;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FiscalProviderFixture;

/**
 * F3-01 — los CONJUNTOS CERRADOS de la administracion fiscal.
 *
 * La pantalla de la administracion fiscal muestra CATALOGOS: los papeles de
 * una serie, las autoridades que asignan el numero y los canales de emision.
 * Y cada catalogo tiene que ser el MISMO que usa el dominio, porque un
 * catalogo que se aparta del dominio es una pantalla que ofrece declarar
 * algo que el dominio no entiende.
 *
 * Y POR QUE ESTE TEST EXISTE, Y NO UNO QUE COPIE LOS VALORES A MANO: el
 * conjunto cerrado de una serie vive en el ESQUEMA (la restriccion CHECK de
 * la columna), y el conjunto cerrado de los canales vive en el DOMINIO (el
 * `match` del resolutor, que es privado). El enum de la administracion y el
 * catalogo de la pantalla son ESPEJOS de esas dos verdades, y un espejo que
 * se aparta es un espejo roto: por eso el test los compara con las fuentes,
 * y no con una lista escrita aqui (que seria una tercera verdad).
 *
 * REGLA que fija este fichero: *la administracion fiscal no puede declarar
 * un conjunto cerrado distinto del que declara el dominio*.
 */

/**
 * Los valores que el ESQUEMA admite en una columna con conjunto cerrado.
 *
 * En PostgreSQL, el `enum` de Laravel es una restriccion CHECK: la columna
 * es de texto y la restriccion dice los valores que caben. El test la lee del
 * catalogo del propio motor, y no de una lista escrita a mano.
 *
 * @return list<string> Los valores, ordenados.
 */
function conjunto_del_esquema(string $tabla, string $columna): array
{
    $filas = DB::select(
        'select pg_get_constraintdef(oid) as definicion from pg_constraint '
        ."where conrelid = '{$tabla}'::regclass and contype = 'c'"
    );

    $valores = [];

    foreach ($filas as $fila) {
        $definicion = (string) $fila->definicion;

        if (! str_contains($definicion, $columna)) {
            continue;
        }

        preg_match_all("/'([^']+)'::character varying/", $definicion, $coincidencias);

        foreach ($coincidencias[1] as $valor) {
            $valores[] = $valor;
        }
    }

    sort($valores);

    return $valores;
}

/**
 * Los PAPELES de una serie son los que el ESQUEMA admite.
 *
 * REGLA que fija: *el conjunto cerrado de los papeles de una serie es el del
 * esquema*. El papel decide QUE numeracion es (la del documento, la del
 * numero de control o la del comprobante de retencion), y el esquema es el
 * que dice que papeles existen. El enum de la administracion es su espejo,
 * y este test dice que el espejo no se ha apartado.
 */
test('test_el_conjunto_cerrado_de_los_papeles_de_una_serie_es_el_del_esquema', function () {
    $delEsquema = conjunto_del_esquema('fiscal_series', 'series_role');

    $delEnum = array_column(SeriesRole::cases(), 'value');

    sort($delEnum);

    expect($delEsquema)->not->toBeEmpty('El esquema no declara el conjunto cerrado de los papeles.')
        ->and($delEnum)->toBe($delEsquema, 'Los papeles de la administracion no son los del esquema.');
});

/**
 * Las AUTORIDADES que asignan el numero son las que el ESQUEMA admite.
 *
 * REGLA que fija: *el conjunto cerrado de las autoridades de una serie es el
 * del esquema*. La autoridad dice QUIEN reparte el numero (el emisor, la
 * autoridad que concede el rango, la imprenta digital o el proveedor de
 * pagos), y el esquema es el que dice que autoridades existen.
 */
test('test_el_conjunto_cerrado_de_las_autoridades_de_una_serie_es_el_del_esquema', function () {
    $delEsquema = conjunto_del_esquema('fiscal_series', 'assigned_by');

    $delEnum = array_column(SeriesAuthority::cases(), 'value');

    sort($delEnum);

    expect($delEsquema)->not->toBeEmpty('El esquema no declara el conjunto cerrado de las autoridades.')
        ->and($delEnum)->toBe($delEsquema, 'Las autoridades de la administracion no son las del esquema.');
});

/**
 * Los CANALES que la pantalla dice que no emiten son los que el DOMINIO no
 * puede emitir.
 *
 * REGLA que fija: *la pantalla y el dominio coinciden en que canales
 * emiten*. El dominio decide la implementacion del canal (`ProviderKind`), y
 * un canal sin implementacion NO se emula. La pantalla lo dice con la misma
 * lista, y este test la compara con el comportamiento del resolutor: si un
 * canal se aparta, el aviso de la pantalla es una mentira.
 */
test('test_el_catalogo_de_canales_coincide_con_lo_que_el_dominio_emite', function () {
    $contexto = FiscalProviderFixture::contexto();
    $serie = FiscalProviderFixture::serieDocumento($contexto);

    FiscalProviderFixture::entorno($contexto);

    config(['fiscal.environment' => 'testing']);

    $resolutor = new FiscalProviderResolver(DB::connection());

    foreach (ProviderKind::cases() as $canal) {
        DB::table('fiscal_providers')
            ->where('id', $contexto['provider_id'])
            ->update(['kind' => $canal->value, 'updated_at' => now()]);

        // Lo que el DOMINIO hace con ese canal: emitir o negarse.
        $elDominioEmite = true;

        try {
            $resolutor->resolve($serie);
        } catch (RuntimeException) {
            $elDominioEmite = false;
        }

        expect(FiscalChannels::tieneImplementacion($canal->value))->toBe(
            $elDominioEmite,
            "La pantalla y el dominio no coinciden en el canal '{$canal->value}'."
        );

        // Y el AVISO de la pantalla existe solo cuando el canal no emite.
        $aviso = FiscalChannels::aviso($canal->value);

        if ($elDominioEmite) {
            expect($aviso)->toBeNull("El canal '{$canal->value}' emite y la pantalla lo avisa de que no.");

            continue;
        }

        expect($aviso)->toBeString()
            ->and($aviso)->toContain($canal->value);
    }
});
