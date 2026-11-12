<?php

return [
    // How long a pending enrolment holds its seat while the payer is on
    // Paystack's checkout page. After this the seat goes back on sale.
    'seat_hold_minutes' => (int) env('CLASS_SEAT_HOLD_MINUTES', 30),

    // Classes run on Lagos time: the schedule generator, the catalogue's
    // days/times/dates, and emails all use this. Stored times are UTC.
    'display_timezone' => env('CLASS_DISPLAY_TIMEZONE', 'Africa/Lagos'),

    // Enrolled families get a day's meeting_url from this many minutes
    // before it starts until it ends (the frontend's Join button itself
    // opens 10 minutes before). Admins and the class teacher always see it.
    // Hosts the frontend may send as `redirect_url` on enrol, for where
    // Paystack returns the payer (AllowedCheckoutRedirect). FRONTEND_URL's
    // host is always allowed. Comma-separated, exact hosts.
    'checkout_redirect_hosts' => array_filter(explode(',', (string) env('CHECKOUT_REDIRECT_HOSTS', ''))),

    'meeting_link_reveal_minutes' => (int) env('CLASS_LINK_REVEAL_MINUTES', 60),
];
