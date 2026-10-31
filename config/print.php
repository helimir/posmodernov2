<?php

return [

    /*
     * LAN print agent switch. Off until the print targets and the agent token
     * rotation are validated: printing never depends on the fiscal emission,
     * and the other way around.
     */
    'lan_agent_enabled' => env('PRINT_LAN_AGENT_ENABLED', false),

];
