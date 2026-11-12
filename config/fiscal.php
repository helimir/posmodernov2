<?php

return [

    /*
     * Fiscal emission switch. Off until the fiscal channel is validated for
     * the country in scope: rolling the fiscal rollout back means turning it
     * off, never renumbering issued documents.
     */
    'emission_enabled' => env('FISCAL_EMISSION_ENABLED', false),

    /*
     * The fiscal environment the store emits in. The environment decides
     * WHICH deployment row is used (testing or production), and the
     * deployment row decides the provider.
     *
     * It is a switch between two channels, not a country: the country is in
     * the fiscal profile, as data.
     */
    'environment' => env('FISCAL_ENVIRONMENT', 'testing'),

];
