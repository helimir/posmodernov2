<?php

namespace Tests\Support;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * El ARNES del CAMINO POR HTTP: un servidor de verdad, en un puerto libre,
 * contra una base de datos propia.
 *
 * POR QUE EXISTE, Y POR QUE NO BASTA UN TEST CON `actingAs()`:
 *
 * Un test con `actingAs()` verifica la PAGINA: comprueba que el controlador
 * responde lo que tiene que responder. Un test con `actingAs()` NO verifica
 * el CAMINO: no pasa por el arranque de verdad, ni por el login, ni por el
 * token CSRF, ni por el enrutado real de la peticion.
 *
 * Y el CAMINO ha fallado en produccion dos veces, y las dos veces de un modo
 * que ni el CI veia: el 419 del CSRF (el login no funcionaba en el
 * navegador) y las mayusculas de las rutas de Inertia (en Linux la
 * aplicacion no renderizaba nada). Los dos fallos seMineon al probar el
 * arranque POR HTTP de verdad.
 *
 * QUE HACE, Y POR QUE:
 *
 *  - Levanta un servidor REAL (`php artisan serve`) en un puerto LIBRE: la
 *    maquina no puede fijar un puerto, porque en el CI y en local puede estar
 *    ocupado.
 *  - Levanta una base de datos PROPIA, sembrada y migrada al vuelo, y la
 *    tira al terminar. El camino del navegador no puede usar la base de
 *    pruebas: los datos del servidor tienen que estar COMMITEADOS para que
 *    los vea, y una transaccion abierta no se ve desde otra conexion.
 *  - NO depende de Redis: la sesion va a la base de datos y la cache a la
 *    memoria. Un camino que necesita Redis no es un camino que se pueda
 *    probar en cualquier parte.
 */
final class PosHttpServer
{
    /**
     * Cuanto se espera a que el servidor escuche.
     *
     * Son SEGUNDOS, no milisegundos: el proceso `artisan serve` arranca la
     * aplicacion entera antes de lanzar el servidor de PHP, y ese arranque
     * tarda mas de veinte segundos en una maquina sin cache de opcode. Con
     * treinta segundos de margen, el camino por HTTP se caia por tiempo en
     * el segundo arranque del mismo proceso de pruebas.
     */
    private const ESPERA_ARRANQUE = 120;

    /**
     * El margen de una PETICION, en segundos.
     *
     * El cliente HTTP de Laravel espera treinta segundos por defecto, y ese
     * es el margen de la CONEXION, no el de la respuesta: la primera
     * peticion paga el arranque de la aplicacion entera, que en una maquina
     * sin cache de opcode tarda mas de veinte segundos. Con treinta
     * segundos, el camino se caia por el reloj y no por el camino.
     */
    private const ESPERA_PETICION = 180;

    /**
     * El CLIENTE HTTP del camino: con las cookies de la sesion y con margen.
     *
     * REGLA que fija este metodo: *toda peticion del camino sale por aqui*.
     * Un cliente con el margen por defecto se cae en el primer arranque, y
     * ese fallo se confunde con un fallo del camino, que es peor: hace
     * dudar de la aplicacion cuando el problema es el reloj.
     *
     * @param  CookieJar|null  $jar  Las cookies de la sesion.
     * @param  array<string, mixed>  $opciones  Las opciones que se anaden.
     */
    public function cliente(?CookieJar $jar = null, array $opciones = []): PendingRequest
    {
        $ajuste = [
            'timeout' => self::ESPERA_PETICION,
            'connect_timeout' => 30,
        ];

        // Las cookies SOLO si las hay: Guzzle tira la opcion cuando el valor
        // es nulo, y el cliente de Laravel la lee igual, y revienta con
        // "Undefined array key cookies". Un cliente SIN sesion es un cliente
        // sin la opcion, no un cliente con la opcion a nulo.
        if ($jar !== null) {
            $ajuste['cookies'] = $jar;
        }

        return Http::withOptions(array_merge($ajuste, $opciones));
    }

    /**
     * @param  Process  $process  El servidor, vivo.
     * @param  string  $database  La base de datos del camino, de un solo uso.
     * @param  string  $port  El puerto donde escucha.
     * @param  string|null  $originalDatabase  La base de datos que habia antes
     *                                         de este camino, para devolverla.
     */
    private function __construct(
        private readonly Process $process,
        private readonly string $database,
        private readonly string $port,
        private readonly ?string $originalDatabase,
    ) {}

    /**
     * Levanta el servidor y devuelve el arnes.
     *
     * @param  array<string, string>  $entorno  Variables de entorno ADICIONALES
     *                                          para el servidor. Un camino que
     *                                          depende de un interruptor de
     *                                          modulo (el de emision fiscal) los
     *                                          declara aqui: el servidor es un
     *                                          proceso aparte, y no hereda el
     *                                          entorno del proceso de pruebas.
     */
    public static function start(array $entorno = []): self
    {
        $original = config('database.connections.pgsql.database');
        $database = 'pos_moderno_v2_http_'.bin2hex(random_bytes(4));

        // 1. La base de datos DEL CAMINO: la suya, y se tira al terminar.
        DB::statement('CREATE DATABASE "'.$database.'"');

        // 2. El proceso de pruebas pasa a apuntar a ella.
        config(['database.connections.pgsql.database' => $database]);
        DB::purge('pgsql');

        // 3. El esquema: el servidor tiene que encontrar lo que espera.
        Artisan::call('migrate', ['--force' => true]);

        // 4. El servidor, en un puerto libre.
        $port = self::freePort();

        $process = new Process(
            [PHP_BINARY, 'artisan', 'serve', '--no-reload', '--host=127.0.0.1', '--port='.$port],
            base_path(),
            self::environment($database, $port, $entorno),
            null,
            null,
        );

        $process->start();

        $servidor = new self($process, $database, $port, is_string($original) ? $original : null);

        $servidor->esperar();

        return $servidor;
    }

    /**
     * La URL de una ruta del servidor.
     */
    public function url(string $ruta = '/'): string
    {
        return 'http://127.0.0.1:'.$this->port.$ruta;
    }

    /**
     * El puerto donde escucha el servidor.
     */
    public function port(): string
    {
        return $this->port;
    }

    /**
     * Para el servidor: su base de datos, su sesion y su cache.
     *
     * Las variables se pasan EXPLICITAMENTE, y no por herencia: el servidor
     * tiene que hablar con la base de datos del camino, no con la de
     * desarrollo, y la cache no puede depender de Redis.
     *
     * @param  array<string, string>  $extra  Variables ADICIONALES: las del
     *                                        camino, que el proceso de
     *                                        pruebas no puede heredar.
     * @return array<string, string>
     */
    private static function environment(string $database, string $port, array $extra = []): array
    {
        $conexion = config('database.connections.pgsql');

        return array_merge([
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'true',
            'APP_KEY' => (string) config('app.key'),
            'APP_URL' => 'http://127.0.0.1:'.$port,
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $conexion['host'],
            'DB_PORT' => (string) $conexion['port'],
            'DB_DATABASE' => $database,
            'DB_USERNAME' => (string) $conexion['username'],
            'DB_PASSWORD' => (string) $conexion['password'],
            // La sesion va a la base de datos: con el almacen en memoria el
            // login no sobrevive a la peticion siguiente.
            'SESSION_DRIVER' => 'database',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            // La emision fiscal APAGADA, como en el resto de la suite: el
            // servidor del camino no hereda el `.env` de la maquina, y un
            // interruptor encendido ahi cambiaria el camino que se prueba.
            // El camino que necesita la emision la declara en el entorno que
            // pasa a `start()`.
            'FISCAL_EMISSION_ENABLED' => 'false',
        ], $extra);
    }

    /**
     * ESPERA a que el servidor responda.
     *
     * Un servidor que no arranca es un fallo del camino, no un fallo
     * silencioso: el mensaje dice que paso.
     *
     * REGLA que fija este metodo: *esperar al servidor es esperar DECISEGUNDOS,
     * no milisegundos*. El proceso `artisan serve` arranca la aplicacion
     * entera ANTES de lanzar el servidor de PHP, y en una maquina sin cache de
     * opcode (el CLI de PHP no la trae) ese arranque son mas de veinte
     * segundos: medidos, 20,98 s y 24,09 s en dos arranques seguidos en la
     * misma maquina. Con el limite de treinta segundos, el camino por HTTP se
     * caia por TIEMPO en el segundo arranque del mismo proceso de pruebas, y
     * el fallo no era del camino: era del reloj.
     */
    private function esperar(): void
    {
        $limite = microtime(true) + self::ESPERA_ARRANQUE;

        while (microtime(true) < $limite) {
            if (! $this->process->isRunning()) {
                throw new RuntimeException(
                    'El servidor del camino por HTTP se paró: '
                    .trim($this->process->getOutput().' '.$this->process->getErrorOutput())
                );
            }

            $conexion = @fsockopen('127.0.0.1', (int) $this->port, $errno, $error, 0.5);

            if ($conexion !== false) {
                fclose($conexion);

                return;
            }

            usleep(100000);
        }

        throw new RuntimeException('El servidor del camino por HTTP no arranco en el puerto '.$this->port.'.');
    }

    /**
     * Un puerto LIBRE: lo pide el sistema y lo suelta.
     */
    private static function freePort(): string
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            throw new RuntimeException('No se pudo encontrar un puerto libre: '.$error);
        }

        $nombre = (string) stream_socket_get_name($socket, false);

        fclose($socket);

        $punto = strrpos($nombre, ':');

        if ($punto === false) {
            throw new RuntimeException('No se pudo leer el puerto libre: '.$nombre);
        }

        return substr($nombre, $punto + 1);
    }

    /**
     * Apaga el servidor, devuelve la base de datos y tira la del camino.
     */
    public function stop(): void
    {
        $this->process->stop(5);

        // El proceso de pruebas vuelve a su base de datos.
        if ($this->originalDatabase !== null) {
            config(['database.connections.pgsql.database' => $this->originalDatabase]);
        }

        DB::purge('pgsql');

        // Y la base de datos del camino se va con el.
        try {
            DB::statement(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
                [$this->database]
            );

            DB::statement('DROP DATABASE IF EXISTS "'.$this->database.'"');
        } catch (Throwable) {
            // Una base de datos que se queda no es un camino fallido: el
            // camino ya termino, y el servidor ya esta apagado.
        }
    }
}
