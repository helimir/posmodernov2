/**
 * La ARITMETICA de la caja: los importes y las cantidades son TEXTO decimal.
 *
 * REGLA que fija este fichero: *un importe nunca pasa por un `float`*. Las
 * columnas son `numeric(25,4)` y `0.1 + 0.2` no es `0.3` en la aritmetica
 * binaria: un impuesto redondeado por un error de coma flotante es dinero
 * mal. Toda la aritmetica de la pantalla se hace con enteros BIGINT sobre
 * texto decimal, que es lo que hace el servidor.
 *
 * QUE HACE:
 *
 *  - `addAmount`, `subtractAmount` y `multiplyAmount`: la aritmetica del
 *    carrito (precio x cantidad - descuento). Es trivial y NO lleva impuesto:
 *    el impuesto con la tasa vigente lo calcula el MOTOR, en el servidor, y
 *    el navegador no lo replica (una segunda verdad diverge).
 *  - `formatAmount`: el importe con el simbolo, los decimales y los
 *    separadores de la MONEDA de la tienda. Los separadores son DATO: un
 *    literal en la pantalla seria un fork por pais.
 *  - `keypadAmount`: el importe tecleado en el teclado numerico, donde los
 *    ultimos digitos son los centimos de la moneda.
 */

/**
 * La MONEDA de la tienda, tal y como la entrega el servidor.
 */
export interface PosCurrency {
    code: string;
    symbol: string | null;
    symbol_position: string | null;
    decimals: number;
    thousand_separator: string | null;
    decimal_separator: string | null;
}

/**
 * La escala de las columnas: `numeric(25,4)`.
 */
const SCALE = 4;

/**
 * El factor de la escala: `10^4`.
 */
const UNITS = 10n ** BigInt(SCALE);

/**
 * El importe como UNIDADES enteras: el texto decimal multiplicado por
 * `10^4`. Un texto que no es un numero decimal es cero: la pantalla no
 * teaches un `NaN` al cajero.
 */
function toUnits(value: string | null | undefined): bigint {
    const text = (value ?? '').trim();

    if (!/^-?\d+(\.\d+)?$/.test(text)) {
        return 0n;
    }

    const negative = text.startsWith('-');
    const body = negative ? text.slice(1) : text;
    const [integer, fraction = ''] = body.split('.');

    const digits = integer + fraction.slice(0, SCALE).padEnd(SCALE, '0');

    const units = BigInt(digits);

    return negative ? -units : units;
}

/**
 * Las UNIDADES enteras como texto decimal de cuatro decimales.
 */
function fromUnits(units: bigint): string {
    const negative = units < 0n;
    const absolute = negative ? -units : units;

    const integer = absolute / UNITS;
    const fraction = (absolute % UNITS).toString().padStart(SCALE, '0');

    return (negative ? '-' : '') + integer.toString() + '.' + fraction;
}

/**
 * La division con redondeo HALF UP, como el motor: la mitad se va hacia
 * fuera del cero.
 */
function divideHalfUp(value: bigint, divisor: bigint): bigint {
    const half = divisor / 2n;

    return value >= 0n ? (value + half) / divisor : (value - half) / divisor;
}

/**
 * La SUMA de dos importes.
 */
export function addAmount(a: string, b: string): string {
    return fromUnits(toUnits(a) + toUnits(b));
}

/**
 * La RESTA de dos importes.
 */
export function subtractAmount(a: string, b: string): string {
    return fromUnits(toUnits(a) - toUnits(b));
}

/**
 * El PRODUCTO de dos importes, redondeado a la escala de las columnas.
 */
export function multiplyAmount(a: string, b: string): string {
    return fromUnits(divideHalfUp(toUnits(a) * toUnits(b), UNITS));
}

/**
 * La COMPARACION de dos importes: `-1`, `0` o `1`.
 */
export function compareAmounts(a: string, b: string): number {
    const left = toUnits(a);
    const right = toUnits(b);

    if (left === right) {
        return 0;
    }

    return left > right ? 1 : -1;
}

/**
 * El importe SIN simbolo, con los decimales y los separadores de la moneda.
 *
 * El importe se redondea a los decimales de la MONEDA (que son menos que los
 * de la columna): el cajero ve lo que se cobra, con los centavos de su
 * moneda, no las cuatro decimales del almacenamiento.
 */
export function formatPlainAmount(value: string, currency: PosCurrency): string {
    const decimals = Math.max(0, currency.decimals);
    const factor = 10n ** BigInt(SCALE - decimals);

    const rounded = divideHalfUp(toUnits(value), factor);

    const negative = rounded < 0n;
    const absolute = negative ? -rounded : rounded;

    const integer = groupDigits((absolute / factor).toString(), currency.thousand_separator);
    const fraction = decimals > 0 ? (currency.decimal_separator ?? '.') + (absolute % factor).toString().padStart(decimals, '0') : '';

    return (negative ? '-' : '') + integer + fraction;
}

/**
 * El importe con el simbolo de la moneda, en la posicion que declara la
 * moneda.
 */
export function formatAmount(value: string, currency: PosCurrency): string {
    const text = formatPlainAmount(value, currency);
    const symbol = currency.symbol;

    if (!symbol) {
        return text;
    }

    return currency.symbol_position === 'after' ? `${text} ${symbol}` : `${symbol} ${text}`;
}

/**
 * Los digitos de la parte entera, agrupados de tres en tres.
 */
function groupDigits(digits: string, separator: string | null): string {
    if (!separator) {
        return digits;
    }

    let grouped = '';

    for (let indice = 0; indice < digits.length; indice++) {
        const desdeLaDerecha = digits.length - indice;

        if (indice > 0 && desdeLaDerecha % 3 === 0) {
            grouped += separator;
        }

        grouped += digits[indice];
    }

    return grouped;
}

/**
 * El importe del TECLADO NUMERICO: los ultimos digitos son los centimos.
 *
 * Es el comportamiento de siempre: teclear "5000" en una moneda de dos
 * decimales son cincuenta, y teclear "5" son cinco centimos. La coma decimal
 * no se teclea: la moneda decide donde caen los decimales.
 */
export function keypadAmount(digits: string, currency: PosCurrency): string {
    const decimals = Math.max(0, currency.decimals);
    const clean = digits.replace(/\D/g, '');

    if (clean === '') {
        return '0';
    }

    const padded = clean.padStart(decimals + 1, '0');
    const integer = padded.slice(0, padded.length - decimals);
    const fraction = padded.slice(padded.length - decimals);

    return decimals > 0 ? integer + '.' + fraction : integer;
}
