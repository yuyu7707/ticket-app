<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pending Hold Minutes
    |--------------------------------------------------------------------------
    |
    | Minutes a pending order keeps its tickets held before they are released.
    | The Stripe payment page closes just before this, and Stripe requires it
    | to stay open for at least 30 minutes, so this must be 31 or more.
    |
    */

    'pending_hold_minutes' => 31,

];
