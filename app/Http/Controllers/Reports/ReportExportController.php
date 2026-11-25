<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\CashBookReport;
use App\Domain\Reports\CashCloseReport;
use App\Domain\Reports\CustomerAgingReport;
use App\Domain\Reports\ProfitAndLossReport;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportScope;
use App\Domain\Reports\SalesReport;
use App\Domain\Reports\ValuedInventoryReport;
use App\Domain\Reports\VatByRateReport;
use App\Support\Reports\CsvWriter;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La EXPORTACION de los reportes: el fichero CSV que sale del sistema.
 *
 * REGLA que fija esta clase: *el CSV se escribe A MANO y no se gana ninguna
 * dependencia*. El repositorio no puede añadir un paquete de composer por
 * escribir texto, y el "PDF" no es un PDF: es IMPRIMIR DESDE EL NAVEGADOR,
 * que es lo que ya hace el Z y el comprobante (HTML con `@media print`).
 *
 * Y LA INYECCION DE FORMULA se neutraliza en el escritor (`CsvWriter::cell`):
 * un valor que empieza por `=`, `+`, `-` o `@` se ejecuta cuando Excel lo
 * abre, y aqui los nombres de producto, de cliente y de tienda son texto que
 * puede traer eso. Los IMPORTES salen como numeros: son decimales que el
 * sistema produce y valida, y un numero no es una formula.
 *
 * Y EL CONJUNTO DE REPORTES ES CERRADO: la ruta declara los nombres que
 * existen, y un nombre que no esta en la lista no es un reporte. La
 * peticion nunca llega a una consulta.
 *
 * Y EL FICHERO lleva las FILAS del reporte, y nada mas: los totales son
 * cosa de la PANTALLA (la fila de pie), y un fichero con una fila de
 * "total" mezclada entre los datos es un fichero que se importa mal. Y el
 * HUECO del reporte fiscal (las ventas sin comprobante) tampoco va en las
 * filas del impuesto: son dos hechos distintos, y el fichero del IVA es el
 * IVA.
 */
class ReportExportController extends ReportController
{
    /**
     * La MARCA DE ORDEN DE BYTES de UTF-8: Excel la necesita para leer los
     * acentos de un `.csv` sin romperse.
     */
    private const BOM = "\xEF\xBB\xBF";

    /**
     * Los REPORTES que se pueden exportar, con el nombre del fichero.
     *
     * @return array<string, string>
     */
    public static function reportes(): array
    {
        return [
            'ventas' => 'ventas-por-periodo',
            'ventas-productos' => 'ventas-por-producto',
            'ventas-cajeros' => 'ventas-por-cajero',
            'iva' => 'iva-por-alicuota',
            'resultados' => 'perdidas-y-ganancias',
            'libro-caja' => 'libro-de-caja',
            'clientes' => 'aging-de-clientes',
            'inventario' => 'inventario-valorado',
            'arqueo' => 'arqueo',
            'z' => 'z-por-periodo',
        ];
    }

    /**
     * La EXPORTACION de un reporte, como fichero CSV.
     */
    public function exportar(Request $request, string $reporte): StreamedResponse
    {
        $periodo = $this->periodo($request);
        $ambito = $this->ambito($request);

        [$cabeceras, $filas] = $this->contenido($reporte, $ambito, $periodo);

        $nombre = self::reportes()[$reporte].'-'.$periodo->from->format('Y-m-d')
            .'_'.$periodo->to->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($cabeceras, $filas): void {
            echo self::BOM;
            echo CsvWriter::document(array_merge([$cabeceras], $filas));
        }, $nombre, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * El CONTENIDO del reporte exportado: las cabeceras y las filas.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function contenido(string $reporte, ReportScope $ambito, ReportPeriod $periodo): array
    {
        return match ($reporte) {
            'ventas' => $this->ventasPorPeriodo($periodo),
            'ventas-productos' => $this->ventasPorProducto($periodo),
            'ventas-cajeros' => $this->ventasPorCajero($periodo),
            'iva' => $this->iva($ambito, $periodo),
            'resultados' => $this->resultados($ambito, $periodo),
            'libro-caja' => $this->libroDeCaja($ambito, $periodo),
            'clientes' => $this->aging($ambito, $periodo),
            'inventario' => $this->inventario($ambito),
            'arqueo' => $this->arqueo($ambito, $periodo),
            'z' => $this->z($ambito, $periodo),
            default => throw new RuntimeException("El reporte '{$reporte}' no existe."),
        };
    }

    /**
     * Las VENTAS por periodo.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function ventasPorPeriodo(ReportPeriod $periodo): array
    {
        $reporte = (new SalesReport($this->connection))->byPeriod($periodo);

        $filas = [];

        foreach ($reporte['rows'] as $row) {
            $filas[] = [
                $row['date'],
                CsvWriter::cell($row['store_name']),
                (string) $row['sales_count'],
                CsvWriter::amount($row['subtotal']),
                CsvWriter::amount($row['discount']),
                CsvWriter::amount($row['tax_total']),
                CsvWriter::amount($row['grand_total']),
            ];
        }

        return [
            ['Date', 'Store', 'Sales', 'Subtotal', 'Discount', 'Tax', 'Total'],
            $filas,
        ];
    }

    /**
     * Las VENTAS por producto.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function ventasPorProducto(ReportPeriod $periodo): array
    {
        $reporte = (new SalesReport($this->connection))->byProduct($periodo);

        $filas = [];

        foreach ($reporte['rows'] as $row) {
            $filas[] = [
                CsvWriter::cell($row['code']),
                CsvWriter::cell($row['name']),
                CsvWriter::cell($row['store_name']),
                CsvWriter::amount($row['quantity']),
                CsvWriter::amount($row['net_amount']),
                CsvWriter::amount($row['tax_amount']),
                CsvWriter::amount($row['total_amount']),
            ];
        }

        return [
            ['Code', 'Name', 'Store', 'Quantity', 'Net', 'Tax', 'Total'],
            $filas,
        ];
    }

    /**
     * Las VENTAS por cajero.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function ventasPorCajero(ReportPeriod $periodo): array
    {
        $reporte = (new SalesReport($this->connection))->byCashier($periodo);

        $filas = [];

        foreach ($reporte['rows'] as $row) {
            $filas[] = [
                CsvWriter::cell($row['name'] ?? ''),
                (string) $row['sales_count'],
                CsvWriter::amount($row['subtotal']),
                CsvWriter::amount($row['tax_total']),
                CsvWriter::amount($row['grand_total']),
            ];
        }

        return [
            ['Cashier', 'Sales', 'Subtotal', 'Tax', 'Total'],
            $filas,
        ];
    }

    /**
     * El IVA por alicuota.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function iva(ReportScope $ambito, ReportPeriod $periodo): array
    {
        $reporte = (new VatByRateReport($this->connection))->build($ambito, $periodo);

        $filas = [];

        foreach ($reporte['rows'] as $row) {
            $filas[] = [
                CsvWriter::cell($row['tax_type']),
                CsvWriter::amount($row['tax_rate'] ?? '0'),
                CsvWriter::amount($row['base_amount']),
                CsvWriter::amount($row['tax_amount']),
                (string) $row['documents_count'],
            ];
        }

        return [
            ['Tax type', 'Rate', 'Base', 'Tax', 'Documents'],
            $filas,
        ];
    }

    /**
     * El resultado del periodo.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function resultados(ReportScope $ambito, ReportPeriod $periodo): array
    {
        $reporte = (new ProfitAndLossReport($this->connection))->build($ambito, $periodo);

        $filas = [];

        foreach ($reporte['rows'] as $row) {
            $filas[] = [
                CsvWriter::cell($row['account_code']),
                CsvWriter::cell($row['account_name']),
                CsvWriter::cell($row['kind']),
                CsvWriter::amount($row['debit']),
                CsvWriter::amount($row['credit']),
                CsvWriter::amount($row['amount']),
            ];
        }

        return [
            ['Account', 'Name', 'Kind', 'Debit', 'Credit', 'Amount'],
            $filas,
        ];
    }

    /**
     * El libro de caja.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function libroDeCaja(ReportScope $ambito, ReportPeriod $periodo): array
    {
        $reporte = (new CashBookReport($this->connection))->build($ambito, $periodo);

        $filas = [];

        foreach ($reporte['rows'] as $row) {
            $filas[] = [
                $row['date'],
                CsvWriter::cell($row['store_name']),
                CsvWriter::amount($row['opening_balance']),
                CsvWriter::amount($row['debit']),
                CsvWriter::amount($row['credit']),
                CsvWriter::amount($row['closing_balance']),
            ];
        }

        return [
            ['Date', 'Store', 'Opening', 'Debit', 'Credit', 'Closing'],
            $filas,
        ];
    }

    /**
     * El aging de clientes.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function aging(ReportScope $ambito, ReportPeriod $periodo): array
    {
        $reporte = (new CustomerAgingReport($this->connection))->build($ambito, $periodo);

        $filas = [];

        foreach ($reporte['rows'] as $row) {
            $filas[] = [
                CsvWriter::cell($row['name'] ?? ''),
                CsvWriter::cell($row['store_name']),
                CsvWriter::amount($row['balance']),
                CsvWriter::amount($row['due']),
                CsvWriter::amount($row['credit']),
                $row['oldest_date'] ?? '',
                $row['age_days'] === null ? '' : (string) $row['age_days'],
            ];
        }

        return [
            ['Customer', 'Store', 'Balance', 'Due', 'Credit', 'Oldest charge', 'Age (days)'],
            $filas,
        ];
    }

    /**
     * El inventario valorado.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function inventario(ReportScope $ambito): array
    {
        $reporte = (new ValuedInventoryReport($this->connection))->build($ambito);

        $filas = [];

        foreach ($reporte['rows'] as $row) {
            $filas[] = [
                CsvWriter::cell($row['code']),
                CsvWriter::cell($row['name']),
                CsvWriter::cell($row['store_name']),
                CsvWriter::amount($row['quantity']),
                CsvWriter::amount($row['value']),
            ];
        }

        return [
            ['Code', 'Name', 'Store', 'Quantity', 'Value'],
            $filas,
        ];
    }

    /**
     * El arqueo.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function arqueo(ReportScope $ambito, ReportPeriod $periodo): array
    {
        $reporte = (new CashCloseReport($this->connection))->cashCount($ambito, $periodo);

        $filas = [];

        foreach ($reporte['rows'] as $row) {
            $filas[] = [
                $row['date'],
                CsvWriter::cell($row['store_name']),
                CsvWriter::amount($row['opening_balance']),
                CsvWriter::amount($row['expected_balance']),
                CsvWriter::amount($row['counted_balance']),
                CsvWriter::amount($row['difference']),
                $row['verified'] ? 'yes' : 'no',
            ];
        }

        return [
            ['Date', 'Store', 'Opening', 'Expected', 'Counted', 'Difference', 'Verified'],
            $filas,
        ];
    }

    /**
     * El Z por periodo.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function z(ReportScope $ambito, ReportPeriod $periodo): array
    {
        $reporte = (new CashCloseReport($this->connection))->periodZ($ambito, $periodo);

        $filas = [];

        foreach ($reporte['rows'] as $row) {
            $filas[] = [
                $row['date'],
                CsvWriter::cell($row['store_name']),
                (string) $row['sales_count'],
                CsvWriter::amount($row['subtotal']),
                CsvWriter::amount($row['tax_total']),
                CsvWriter::amount($row['grand_total']),
                CsvWriter::cell($row['first_document_number'] ?? ''),
                CsvWriter::cell($row['last_document_number'] ?? ''),
                $row['verified'] ? 'yes' : 'no',
            ];
        }

        return [
            ['Date', 'Store', 'Sales', 'Subtotal', 'Tax', 'Total', 'First document', 'Last document', 'Verified'],
            $filas,
        ];
    }
}
