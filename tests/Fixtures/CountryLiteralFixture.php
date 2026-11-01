<?php

/**
 * FIXTURE (no forma parte del dominio): sirve para probar que el escáner de
 * literales de país DETECTA un literal prohibido. Si este fichero no está
 * marcado, el escáner es vacuo y su "verde" no prueba nada.
 */
final class CountryLiteralFixture
{
    public function countryCode(): string
    {
        return 'VE';
    }
}
