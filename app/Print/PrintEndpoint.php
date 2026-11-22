<?php

namespace App\Print;

use RuntimeException;

/**
 * El ENDPOINT de un destino de impresion: DONDE se escriben los bytes.
 *
 * REGLA que fija esta clase: *el endpoint dice donde se escribe, y sólo se
 * admiten las dos formas que el esquema admite*.
 *
 *  - la IMPRESORA DE RED: `tcp://servidor:<puerto>`. El 9100 es el RAW del
 *    estándar ESC/POS de facto, pero NO es una ley: hay impresoras de red en
 *    el 9105, en el 515 (LPD) o en el 631 (IPP), y un POS que no puede
 *    configurar su impresora es un POS que se queda sin ticket. El PUERTO es
 *    el que tenga, siempre que sea uno de TCP (de 1 a 65535);
 *  - la IMPRESORA COMPARTIDA de WINDOWS: `smb://servidor/impresora` o el
 *    recurso UNC `\\servidor\impresora`. Son la MISMA cola escrita de dos
 *    maneras, y la regla dice como se quita el esquema.
 *
 * Y LO QUE NO SE ADMITE: el USB DIRECTO (en Windows exige Zadig) y el
 * esquema `file://` (un destino no es un fichero local). El USB ya lo rechaza
 * el esquema; esta clase es la segunda puerta, la que no depende de la base
 * de datos.
 *
 * Y LA CONVERSION ES EXPLICITA, no una heuristica: el `endpoint` guarda el
 * esquema, y el escritor necesita la RUTA de Windows. La conversion va de
 * `smb://servidor/recurso` a `\\servidor\recurso`, y un recurso UNC ya ES la
 * ruta de Windows.
 *
 * Y UN ENDPOINT QUE NO ESTA EN LA LISTA ADMITIDA NO SE ESCRIBE A CIEGAS:
 * falla con un mensaje que dice QUE formas se admiten.
 */
final readonly class PrintEndpoint
{
    /**
     * El destino es una IMPRESORA DE RED: se escribe en un socket.
     */
    public const FORMA_SOCKET = 'socket';

    /**
     * El destino es la COLA de la impresora compartida de Windows.
     */
    public const FORMA_COLA = 'cola';

    /**
     * El puerto MINIMO de un socket TCP.
     */
    public const PUERTO_MINIMO = 1;

    /**
     * El puerto MAXIMO de un socket TCP.
     */
    public const PUERTO_MAXIMO = 65535;

    /**
     * @param  string  $forma  `socket` o `cola`.
     * @param  int|null  $puerto  El puerto del socket, si lo hay.
     * @param  string  $cola  La ruta de Windows de la cola, si la hay.
     */
    private function __construct(
        public string $texto,
        public string $forma,
        public string $host,
        public ?int $puerto,
        public string $cola,
    ) {}

    /**
     * El ENDPOINT de un destino, o un error con mensaje útil.
     *
     * @throws RuntimeException Si el endpoint no está en la lista admitida.
     */
    public static function deTexto(?string $texto): self
    {
        $endpoint = trim((string) $texto);

        if ($endpoint === '') {
            throw new RuntimeException(
                'El destino de impresion no declara endpoint: no hay donde escribir los bytes.'
            );
        }

        // 1. LA IMPRESORA DE RED: tcp://servidor:<puerto>.
        //
        // El PUERTO es el que tenga la impresora, siempre que sea uno de TCP:
        // el 9100 es el RAW de facto del estandar ESC/POS, pero no es una ley,
        // y un POS que no puede configurar su impresora se queda sin ticket.
        if (preg_match('/^tcp:\/\/([A-Za-z0-9._-]+):(\d{1,5})$/', $endpoint, $coincidencia) === 1) {
            $puerto = (int) $coincidencia[2];

            if ($puerto < self::PUERTO_MINIMO || $puerto > self::PUERTO_MAXIMO) {
                throw new RuntimeException(self::noAdmitido($endpoint));
            }

            return new self($endpoint, self::FORMA_SOCKET, $coincidencia[1], $puerto, '');
        }

        // 2. LA IMPRESORA COMPARTIDA, con el esquema: smb://servidor/recurso.
        if (str_starts_with($endpoint, 'smb://')) {
            return self::colaDesdeEsquema($endpoint, substr($endpoint, strlen('smb://')));
        }

        // 3. LA IMPRESORA COMPARTIDA, como recurso UNC: \\servidor\recurso.
        if (str_starts_with($endpoint, '\\\\')) {
            return self::colaDesdeEsquema($endpoint, substr($endpoint, 2), true);
        }

        throw new RuntimeException(self::noAdmitido($endpoint));
    }

    /**
     * El ENDPOINT de un destino, PARA SU TRANSPORTE, o un error con mensaje
     * útil.
     *
     * REGLA que fija este método: *el endpoint lo decide el TRANSPORTE*.
     *
     *  - el `browser` NO tiene endpoint: el navegador ES el destino, y el
     *    servidor lo que hace es renderizar el comprobante en HTML/PDF desde
     *    el mismo modelo de plantilla. Un endpoint en un destino del
     *    navegador es un destino que no existe;
     *  - los transportes FISICOS lo tienen SIEMPRE: sin endpoint no hay
     *    donde escribir los bytes.
     *
     * Y LA VALIDACION ES DEL DOMINIO, no de PostgreSQL: el CHECK del esquema
     * (`print_targets_endpoint_admitted`) es la red de seguridad, no el
     * mensaje. El que falla tiene que leer QUE endpoint es, QUE transportes
     * admiten endpoint y QUE formas se aceptan.
     *
     * @return string|null El endpoint normalizado, o `null` en el `browser`.
     *
     * @throws RuntimeException Si el endpoint no es admisible en su transporte.
     */
    public static function paraTransporte(PrintTransport $transporte, ?string $texto): ?string
    {
        $endpoint = trim((string) $texto);

        if ($transporte === PrintTransport::Browser) {
            if ($endpoint !== '') {
                throw new RuntimeException(
                    'El transporte «browser» no declara endpoint: el navegador ES el destino, '
                    .'y el comprobante se sirve como HTML/PDF. Los transportes «lan_agent» y '
                    .'«server_socket» si declaran endpoint.'
                );
            }

            return null;
        }

        if ($endpoint === '') {
            throw new RuntimeException(
                "El destino de impresion con el transporte «{$transporte->value}» no declara endpoint: "
                .'no hay donde escribir los bytes. Las formas admitidas son: '.self::formas().'.'
            );
        }

        // La FORMA se valida con la misma puerta que usa el escritor: el
        // destino que se declara tiene que ser un destino que se pueda
        // escribir.
        self::deTexto($endpoint);

        return $endpoint;
    }

    /**
     * Las FORMAS de endpoint que se admiten, para los mensajes.
     *
     * Y EL PUERTO NO SE ANUNCIA COMO SI FUERA UNO SOLO: el 9100 es el RAW de
     * facto del estandar ESC/POS, pero el destino admite el que tenga la
     * impresora, y un mensaje que anuncia solo el 9100 hace creer que las
     * demas impresoras de red no valen.
     */
    public static function formas(): string
    {
        return 'tcp://servidor:<puerto> (la impresora de red, con el puerto que tenga), '
            .'smb://servidor/impresora o \\\\servidor\\impresora (la impresora compartida de Windows)';
    }

    /**
     * ¿El destino es una impresora de RED?
     */
    public function esSocket(): bool
    {
        return $this->forma === self::FORMA_SOCKET;
    }

    /**
     * ¿El destino es la COLA de la impresora compartida de Windows?
     */
    public function esColaDeWindows(): bool
    {
        return $this->forma === self::FORMA_COLA;
    }

    /**
     * La DIRECCION del socket, para `stream_socket_client`.
     */
    public function direccionDeSocket(): string
    {
        return "tcp://{$this->host}:{$this->puerto}";
    }

    /**
     * La RUTA de Windows de la cola de la impresora compartida.
     */
    public function rutaDeCola(): string
    {
        return $this->cola;
    }

    /**
     * La COLA de Windows, desde el recurso del endpoint.
     *
     * La REGLA es una y sola, y es la de Windows: el recurso es
     * `servidor\recurso`, con la barra INVERTIDA. El endpoint puede traer la
     * barra hacia delante (la forma `smb://`) o hacia atras (la forma UNC), y
     * las dos dicen lo mismo.
     *
     * @param  bool  $separador  `true` si el recurso viene con barras
     *                           invertidas (la forma UNC).
     *
     * @throws RuntimeException Si el recurso no nombra una impresora.
     */
    private static function colaDesdeEsquema(string $endpoint, string $recurso, bool $separador = false): self
    {
        $partes = explode($separador ? '\\' : '/', $recurso);

        $servidor = trim($partes[0]);

        // El recurso se recorta por los dos lados: la barra final no es parte
        // del nombre de la impresora.
        $impresora = trim(rtrim(trim(implode($separador ? '\\' : '/', array_slice($partes, 1))), '/\\'));

        if ($servidor === '' || $impresora === '') {
            throw new RuntimeException(
                "El endpoint «{$endpoint}» no nombra una impresora compartida: "
                .'falta el recurso de la impresora (smb://servidor/impresora).'
            );
        }

        $ruta = '\\\\'.$servidor.'\\'.str_replace('/', '\\', $impresora);

        return new self($endpoint, self::FORMA_COLA, $servidor, null, $ruta);
    }

    /**
     * El mensaje del endpoint que NO esta en la lista admitida.
     */
    private static function noAdmitido(string $endpoint): string
    {
        return "El endpoint «{$endpoint}» no es un destino admitido: los transportes «lan_agent» y "
            .'«server_socket» escriben en la impresora de red (tcp://servidor:<puerto>) '
            .'o en la impresora compartida de Windows (smb://servidor/impresora o '
            .'\\\\servidor\\impresora). El transporte «browser» no declara endpoint: el navegador es el destino.';
    }
}
