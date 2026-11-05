<?php

use App\Models\BankAccount;
use App\Models\BankAccountStore;
use App\Models\BoxStore;
use App\Models\BrandStore;
use App\Models\CashRegister;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FiscalDocument;
use App\Models\IncomeSource;
use App\Models\OpeningBalance;
use App\Models\PaymentMethodStore;
use App\Models\PosTemplateStore;
use App\Models\UnitStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;

/**
 * 0.7.7 — los modelos contables: cobertura de columnas, casts exactos y
 * relaciones que apuntan a un modelo que EXISTE.
 *
 * Reglas que fija:
 * - un modelo contable cubre TODAS las columnas de su tabla (salvo `id` y las
 *   marcas de tiempo): una columna que no se puede rellenar es una columna
 *   que nadie puede escribir;
 * - el cast de cada columna corresponde al tipo REAL de la columna: un
 *   importe es `decimal:4`, una FK es `integer`, una fecha es `date` o
 *   `datetime`, un booleano es `boolean`;
 * - una relacion apunta a una clase que existe: una relacion a una clase
 *   inexistente revienta en el momento de usarla, no en el de declararla.
 */

/**
 * Los modelos de la unidad de contabilidad y caja, mas los bridges que
 * faltaban y el comprobante fiscal.
 *
 * @return array<int, class-string<Model>>
 */
function modelos_contables(): array
{
    return [
        BankAccount::class,
        BankAccountStore::class,
        BoxStore::class,
        BrandStore::class,
        CashRegister::class,
        Expense::class,
        ExpenseCategory::class,
        FiscalDocument::class,
        IncomeSource::class,
        OpeningBalance::class,
        PaymentMethodStore::class,
        PosTemplateStore::class,
        UnitStore::class,
    ];
}

/**
 * Todos los modelos del repositorio.
 *
 * @return array<int, class-string<Model>>
 */
function modelos_del_repositorio(): array
{
    $modelos = [];

    foreach (glob(app_path('Models/*.php')) ?: [] as $fichero) {
        $clase = 'App\\Models\\'.basename($fichero, '.php');

        if (class_exists($clase) && is_subclass_of($clase, Model::class)) {
            $modelos[] = $clase;
        }
    }

    sort($modelos);

    return $modelos;
}

/**
 * Las relaciones declaradas por un modelo que apuntan a una clase que no
 * existe.
 *
 * @param  class-string<Model>  $modelo
 * @return array<string, string> relacion => error
 */
function relaciones_rotas(string $modelo): array
{
    $rotas = [];
    $instancia = new $modelo;
    $reflexion = new ReflectionClass($modelo);

    foreach ($reflexion->getMethods(ReflectionMethod::IS_PUBLIC) as $metodo) {
        if ($metodo->getDeclaringClass()->getName() !== $modelo) {
            continue;
        }

        $tipo = $metodo->getReturnType();

        if (! $tipo instanceof ReflectionNamedType || ! is_subclass_of($tipo->getName(), Relation::class)) {
            continue;
        }

        try {
            $relacion = $instancia->{$metodo->getName()}();
        } catch (Throwable $e) {
            $rotas[$modelo.'::'.$metodo->getName().'()'] = $e->getMessage();

            continue;
        }

        if (! $relacion instanceof Relation) {
            $rotas[$modelo.'::'.$metodo->getName().'()'] = 'No devuelve una relacion.';
        }
    }

    return $rotas;
}

/**
 * El cast que le corresponde al tipo real de la columna.
 */
function cast_esperado(string $tipo): string
{
    return match (true) {
        str_starts_with($tipo, 'numeric'), str_starts_with($tipo, 'decimal') => 'decimal:4',
        str_starts_with($tipo, 'bigint'), str_starts_with($tipo, 'integer'), str_starts_with($tipo, 'smallint') => 'integer',
        $tipo === 'boolean', $tipo === 'bool' => 'boolean',
        $tipo === 'date' => 'date',
        str_starts_with($tipo, 'timestamp') => 'datetime',
        str_starts_with($tipo, 'character varying'), $tipo === 'text' => 'string',
        default => throw new RuntimeException("Tipo de columna sin cast conocido: {$tipo}"),
    };
}

test('test_cada_modelo_contable_rellena_todas_las_columnas_de_su_tabla', function () {
    foreach (modelos_contables() as $modelo) {
        $instancia = new $modelo;
        $columnas = Schema::getColumnListing($instancia->getTable());

        $esperadas = array_values(array_diff($columnas, ['id', 'created_at', 'updated_at']));

        expect($instancia->getFillable())
            ->toEqualCanonicalizing($esperadas, "El modelo {$modelo} no cubre todas las columnas de su tabla.");
    }
});

test('test_el_cast_de_cada_columna_coincide_con_el_tipo_real', function () {
    foreach (modelos_contables() as $modelo) {
        $instancia = new $modelo;
        $tabla = $instancia->getTable();
        $casts = $instancia->getCasts();

        // Ningun cast apunta a una columna que no existe.
        expect(array_diff(array_keys($casts), Schema::getColumnListing($tabla)))
            ->toBe([], "El modelo {$modelo} declara un cast de una columna que no existe.");

        foreach (Schema::getColumnListing($tabla) as $columna) {
            if (in_array($columna, ['id', 'created_at', 'updated_at'], true)) {
                continue;
            }

            $tipo = Schema::getColumnType($tabla, $columna, true);
            $esperado = cast_esperado($tipo);

            expect(array_key_exists($columna, $casts))
                ->toBeTrue("El modelo {$modelo} no declara el cast de la columna {$columna}.");

            expect($casts[$columna])
                ->toBe($esperado, "El cast de {$modelo}.{$columna} no corresponde a su tipo real ({$tipo}).");
        }
    }
});

test('test_las_relaciones_de_los_modelos_contables_apuntan_a_modelos_que_existen', function () {
    foreach (modelos_contables() as $modelo) {
        expect(relaciones_rotas($modelo))
            ->toBe([], "El modelo {$modelo} declara una relacion a una clase que no existe.");
    }
});

test('test_ningun_modelo_declara_una_relacion_a_una_clase_que_no_existe', function () {
    // DEFECTOS CONOCIDOS: ninguno. Un modelo puede declarar una relacion a
    // una clase que todavia no existe: la relacion revienta en el momento de
    // USARLA, no en el de declararla, y por eso nadie lo ve hasta que alguien
    // la usa.
    //
    // Los seis que habia ya NO estan rotos: `Sale::fiscalDocument()` apunta
    // a `App\Models\FiscalDocument`, y los maestros `Brand`, `Box`, `Unit`,
    // `PaymentMethod` y `PosTemplate` declaran su `stores()` sobre bridges
    // que ahora tienen modelo (`BrandStore`, `BoxStore`, `UnitStore`,
    // `PaymentMethodStore`, `PosTemplateStore`).
    //
    // Cuando aparezca uno nuevo, hay que anadirlo a la lista de conocidos Y
    // anadir su asercion al final de este test.
    $conocidas = [];

    $rotas = [];

    foreach (modelos_del_repositorio() as $modelo) {
        $rotas = array_merge($rotas, relaciones_rotas($modelo));
    }

    // Ninguna relacion NUEVA apunta a una clase inexistente.
    expect(array_diff_key($rotas, $conocidas))
        ->toBe([], 'Hay una relacion nueva que apunta a una clase que no existe.');

    // Y no queda NINGUN defecto conocido por arreglar.
    expect($rotas)->toBe([]);
});
