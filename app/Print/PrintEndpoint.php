<?php

namespace App\Print;

use RuntimeException;

/**
 * El ENDPOINT de un destino de impresion: DONDE se escriben los bytes.
 *
 * REGLA que fija esta clase: *el endpoint dice donde se escribe, y sólo se
 * admiten las dos formas que el esquema admite*.
 *
 *  - la IMPRESORA DE RED: `tcp://servidor:9100`. El puerto es el RAW del
 *    estándar, y el CHECK del esquema (`print_targets_endpoint_admitted`) lo
 *    fija: un destino con otro puerto no es un destino.
 *  - la IMPRESORA COMPARTIDA de WINDOWS: `smb://servidor/impresora` o el
 *    recurso UNC `\\servidor\impresora`. Son la MISMA cola escrita de dos
 *    maneras, y la regla dice como se quita el esquema.
 *
 * Y LA CONVERSION ES EXPLICITA, no una heuristica: el `endpoint` guarda el
 * esquema, y el escritor necesita la RUTA de Windows. La conversion va de
 * `smb://servidor/recurso` a `\\servidor\recurso`, y un recurso UNC ya ES la
 * ruta de Windows.
 *
 * Y UN ENDPOINT QUE NO ESTA EN LA LISTA ADMITIDA NO SE ESCRIBE A CIEGAS:
 * falla con un mensaje que dice QUE formas se admiten. El USB directo no es
 * un destino (exige Zadig), y el esquema ya lo rechaza; esta clase es la
 * segunda puerta, la que no depende de la base de datos.
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
     * El puerto RAW de las impresoras de red: el que admite el esquema.
     */
    public const PUERTO_RAW = 9100;

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

        // 1. LA IMPRESORA DE RED: tcp://servidor:9100.
        if (preg_match('/^tcp:\/\/([A-Za-z0-9._-]+):(\d{1,5})$/', $endpoint, $coincidencia) === 1) {
            $puerto = (int) $coincidencia[2];

            if ($puerto !== self::PUERTO_RAW) {
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
        return "El endpoint «{$endpoint}» no es un destino admitido: solo se escriben la impresora de red "
            .'(tcp://servidor:'.self::PUERTO_RAW.') y la impresora compartida de Windows '
            .'(smb://servidor/impresora o \\\\servidor\\impresora).';
    }
}
