<?php

/**
 * 0.1.3-T — ningún módulo emite, sincroniza ni imprime hasta ser validado y activado.
 */
test('test_los_interruptores_de_modulo_estan_apagados_por_defecto', function () {
    expect(config('fiscal.emission_enabled'))->toBeFalse()
        ->and(config('sync.legacy_adapter_enabled'))->toBeFalse()
        ->and(config('print.lan_agent_enabled'))->toBeFalse();
});
