<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />

        <title>{{ $denomination }} {{ $businessDate }}</title>

        <style>
            /*
             * El PAPEL de la impresora TERMICA: 80 mm.
             *
             * Mismo papel que el comprobante fiscal, porque el Z sale por la
             * misma impresora: los datos van al menos a 6 puntos, y la
             * identificacion del documento al menos a 8.
             */
            @page {
                size: 80mm auto;
                margin: 0;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                background: #f4f4f5;
                color: #18181b;
                font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
            }

            .toolbar {
                display: flex;
                gap: 0.5rem;
                align-items: center;
                padding: 0.75rem 1rem;
                background: #ffffff;
                border-bottom: 1px solid #e4e4e7;
            }

            .toolbar button,
            .toolbar a {
                padding: 0.4rem 0.8rem;
                border: 1px solid #d4d4d8;
                border-radius: 0.375rem;
                background: #ffffff;
                color: #18181b;
                font: inherit;
                font-size: 0.875rem;
                text-decoration: none;
                cursor: pointer;
            }

            .toolbar button:hover,
            .toolbar a:hover {
                background: #f4f4f5;
            }

            .paper {
                width: 80mm;
                margin: 1rem auto;
                padding: 4mm 3mm;
                background: #ffffff;
                box-shadow: 0 1px 3px rgb(0 0 0 / 20%);
            }

            .z {
                font-size: 8pt;
                line-height: 1.35;
            }

            .z__denomination {
                margin: 0 0 0.5rem;
                font-size: 11pt;
                text-align: center;
            }

            .z__rows {
                width: 100%;
                border-collapse: collapse;
            }

            .z__cell {
                padding: 0.05rem 0;
                vertical-align: top;
            }

            .z__cell--label {
                text-align: left;
            }

            .z__cell--value {
                text-align: right;
                white-space: nowrap;
            }

            .z__cell--number {
                text-align: right;
                white-space: nowrap;
            }

            /*
             * La SECCION: el bloque de filas que viene. No es decoracion:
             * divide el Z en las partes que un lector busca.
             */
            .z__section .z__cell {
                padding-top: 0.4rem;
                border-bottom: 1px solid #d4d4d8;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                font-size: 6.5pt;
            }

            .z__row--strong .z__cell {
                font-weight: 700;
            }

            /*
             * La FIRMA del Z: el hash partido en varias lineas, porque no
             * cabe en el ancho del papel.
             */
            .z__cell--hash {
                font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
                font-size: 6pt;
                word-break: break-all;
            }

            .z__warning {
                margin: 0.5rem 0 0;
                padding: 0.35rem;
                border: 1px solid #b91c1c;
                color: #b91c1c;
                font-size: 7pt;
            }

            @media print {
                body {
                    background: #ffffff;
                }

                .toolbar {
                    display: none;
                }

                .paper {
                    width: auto;
                    margin: 0;
                    padding: 0;
                    box-shadow: none;
                }
            }
        </style>
    </head>

    <body>
        <div class="toolbar">
            <button type="button" onclick="window.print()">Print</button>

            <a href="{{ url('/caja/dia') }}">Back to the cash day</a>

            <span class="z__cell--label">
                {{ $denomination }} {{ $businessDate }}
                &middot; {{ $storeName }}
            </span>
        </div>

        <main class="paper">
            {!! $html !!}
        </main>
    </body>
</html>
