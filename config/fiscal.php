<?php

return [

    /*
     * Fiscal emission switch. Off until the fiscal channel is validated for
     * the country in scope: rolling the fiscal rollout back means turning it
     * off, never renumbering issued documents.
     */
    'emission_enabled' => env('FISCAL_EMISSION_ENABLED', false),

];
