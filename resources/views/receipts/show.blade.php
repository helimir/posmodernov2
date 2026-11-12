<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />

        <title>{{ $denomination }} {{ $documentNumber }}</title>

        <style>
            /*
             * El PAPEL de la impresora TERMICA: 80 mm.
             *
             * La tipografia no es decorativa: los datos de la imprenta van al
             * menos a 6 puntos, y la identificacion del emisor y el numero de
             * control al menos a 8. Los puntos los pone el renderer del
             * comprobante; aqui solo se respeta el papel.
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

            .receipt {
                font-size: 8pt;
                line-height: 1.35;
            }

            .receipt h1 {
                margin: 0 0 0.5rem;
                font-size: 11pt;
                text-align: center;
            }

            .receipt__datetime,
            .receipt__numbers {
                margin: 0.25rem 0;
                text-align: center;
            }

            .receipt__party {
                margin: 0.35rem 0;
            }

            .receipt__party-name {
                font-weight: 700;
            }

            .receipt__lines {
                width: 100%;
                margin: 0.5rem 0;
                border-collapse: collapse;
            }

            .receipt__lines td {
                padding: 0.1rem 0;
                vertical-align: top;
            }

            .receipt__lines td:nth-child(2),
            .receipt__lines td:nth-child(3) {
                text-align: right;
                white-space: nowrap;
            }

            .receipt__tax,
            .receipt__total {
                margin: 0.2rem 0;
            }

            .receipt__total,
            .receipt__exempt {
                font-weight: 700;
            }

            .receipt__formal-contributor,
            .receipt__provision {
                margin-top: 0.5rem;
                text-align: center;
            }

            .receipt__printer {
                margin-top: 0.5rem;
            }

            /*
             * El QR: el CONTENIDO del codigo. La imagen del QR la imprime la
             * impresora termica (el renderer ESC/POS), y aqui lo que viaja es
             * el contenido, que es lo que el comprobante declara.
             */
            .receipt__qr {
                margin-top: 0.5rem;
                padding: 0.35rem;
                border: 1px dashed #a1a1aa;
                text-align: center;
                word-break: break-all;
            }

            .receipt__qr-label {
                display: block;
                color: #52525b;
                font-size: 6pt;
                letter-spacing: 0.1em;
            }

            .receipt__qr-content {
                font-size: 6pt;
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

            <a href="{{ url('/ventas') }}">Back to sales</a>

            <span class="receipt__qr-label">
                {{ $denomination }} {{ $documentNumber }}
                @if ($controlNumber !== null)
                    &middot; N&ordm; Control {{ $controlNumber }}
                @endif
                &middot; {{ $issuedAt }}
            </span>
        </div>

        <main class="paper">
            {!! $html !!}

            @if ($qr !== null)
                <div class="receipt__qr" data-qr-content="{{ $qr }}">
                    <span class="receipt__qr-label">QR</span>
                    <span class="receipt__qr-content">{{ $qr }}</span>
                </div>
            @endif
        </main>
    </body>
</html>
