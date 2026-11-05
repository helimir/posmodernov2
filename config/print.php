<?php

return [

    /*
     * LAN print agent switch. Off until the print targets and the agent token
     * rotation are validated: printing never depends on the fiscal emission,
     * and the other way around.
     */
    'lan_agent_enabled' => env('PRINT_LAN_AGENT_ENABLED', false),

    /*
     * How long an agent may go without a heartbeat before it is considered
     * disconnected.
     *
     * This is a PARAMETER and not a column: it changes with the operation
     * (the heartbeat interval), not with the schema. An agent is online while
     * `now() - last_heartbeat_at` is below this threshold. The default is 60
     * seconds, roughly three heartbeat intervals.
     */
    'agent_disconnect_threshold_seconds' => (int) env('PRINT_AGENT_DISCONNECT_THRESHOLD_SECONDS', 60),

];
