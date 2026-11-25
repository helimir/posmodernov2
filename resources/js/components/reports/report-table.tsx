export interface ReportColumn {
    key: string;
    label: string;
    align?: 'left' | 'right';
}

/**
 * A CELL of the table.
 *
 * Every amount is already a STRING by the time it gets here: the backend
 * sends decimal text, and the screen formats it with the CURRENCY of the
 * store. A table that received a number would be a table rounding it twice.
 *
 * A cell can also be an ELEMENT (a badge, for instance): the table draws
 * what it is given.
 */
export interface ReportCell {
    [key: string]: React.ReactNode;
}

interface ReportTableProps {
    columns: ReportColumn[];
    rows: ReportCell[];
    /** What the table says when there is nothing to show. */
    empty: string;
    /** The TOTALS row: the same cells, in bold. */
    footer?: ReportCell | null;
}

export default function ReportTable({ columns, rows, empty, footer = null }: ReportTableProps) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b">
                        {columns.map((column) => (
                            <th key={column.key} className={`px-2 py-2 font-medium ${column.align === 'right' ? 'text-right' : 'text-left'}`}>
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>

                <tbody>
                    {rows.length === 0 ? (
                        <tr>
                            <td colSpan={columns.length} className="text-muted-foreground px-2 py-4 text-sm">
                                {empty}
                            </td>
                        </tr>
                    ) : (
                        rows.map((row, index) => (
                            <tr key={index} className="border-b last:border-0">
                                {columns.map((column) => (
                                    <td key={column.key} className={`px-2 py-2 ${column.align === 'right' ? 'text-right tabular-nums' : ''}`}>
                                        {row[column.key] ?? '-'}
                                    </td>
                                ))}
                            </tr>
                        ))
                    )}
                </tbody>

                {footer && (
                    <tfoot>
                        <tr className="border-t font-medium">
                            {columns.map((column) => (
                                <td key={column.key} className={`px-2 py-2 ${column.align === 'right' ? 'text-right tabular-nums' : ''}`}>
                                    {footer[column.key] ?? ''}
                                </td>
                            ))}
                        </tr>
                    </tfoot>
                )}
            </table>
        </div>
    );
}
