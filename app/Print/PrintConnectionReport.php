<?php

namespace App\Print;

/**
 * El INFORME de la prueba de conexion de un destino.
 *
 * REGLA que fija esta clase: *la prueba de conexion es un RESULTADO, no una
 * excepcion*. El destino puede no ser probable desde el servidor (el agente
 * es quien conecta), y eso no es un fallo: es una respuesta.
 *
 * Y EL ESTADO ES UN CODIGO CERRADO: la pantalla decide con el, y el mensaje
 * es lo que la persona lee.
 */
final readonly class PrintConnectionReport
{
    /**
     * La impresora RESPONDIO: el socket se abrio y se cerro.
     */
    public const CONECTADA = 'connected';

    /**
     * NO se pudo conectar: el destino no esta donde dice estar.
     */
    public const FALLIDA = 'failed';

    /**
     * NO se puede probar desde el servidor: el que conecta es el agente.
     */
    public const NO_PROBABLE = 'not_testable';

    /**
     * NO APLICA: el destino no es fisico (el navegador es el destino).
     */
    public const NO_APLICA = 'not_applicable';

    /**
     * @param  string  $status  El estado: uno de las constantes de esta clase.
     * @param  string  $message  Lo que la persona lee.
     * @param  string|null  $reason  El motivo tecnico del fallo, si lo hay.
     * @param  list<string>  $warnings  Los avisos, como el ancho insuficiente.
     */
    public function __construct(
        public string $status,
        public string $message,
        public ?string $reason = null,
        public array $warnings = [],
    ) {}

    /**
     * ¿La conexion se pudo abrir?
     */
    public function conectada(): bool
    {
        return $this->status === self::CONECTADA;
    }
}
