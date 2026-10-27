<?php

return [
    // Requests per minute per user for claim / redeem / cancel.
    'write_rate_limit_per_minute' => (int) env('COUPON_WRITE_RATE_LIMIT', 30),
];
