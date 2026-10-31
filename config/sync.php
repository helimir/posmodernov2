<?php

return [

    /*
     * Legacy sync adapter switch. Off until the translation map is seeded and
     * audited: turning it off pauses the legacy terminals, it does not freeze
     * them.
     */
    'legacy_adapter_enabled' => env('SYNC_LEGACY_ADAPTER_ENABLED', false),

];
