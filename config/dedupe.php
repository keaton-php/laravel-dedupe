<?php

declare(strict_types=1);

use Keaton\Dedupe\Normalizers\EmailCanonical;
use Keaton\Dedupe\Normalizers\PhoneDigits;

return [

    /*
    |--------------------------------------------------------------------------
    | Match keys
    |--------------------------------------------------------------------------
    |
    | Each entry is one way two records can be "the same". The array key is the
    | name the audit trail reports; the value maps field names to the
    | normalizer class applied to that field (null compares the value as is).
    | A key with several fields only matches when all of them match. Keys are
    | tried in order and the first one that matches is the one reported.
    |
    | Use the shorthand ['email', 'phone'] to compare raw fields.
    |
    */
    'keys' => [
        'email' => ['email' => EmailCanonical::class],
        'phone' => ['phone' => PhoneDigits::class],
    ],

    /*
    |--------------------------------------------------------------------------
    | Window
    |--------------------------------------------------------------------------
    |
    | Two records only collide when their timestamps are within this many
    | seconds of the first record in the group. Null means no window: a match
    | on any key is a duplicate however far apart the records are.
    |
    */
    'window' => env('DEDUPE_WINDOW'),

    /*
    |--------------------------------------------------------------------------
    | Survivorship
    |--------------------------------------------------------------------------
    |
    | Which record of a group is kept: "first" (the earliest in the input) or
    | "last" (the latest in the input).
    |
    */
    'survivorship' => env('DEDUPE_SURVIVORSHIP', 'first'),

    /*
    |--------------------------------------------------------------------------
    | Record fields
    |--------------------------------------------------------------------------
    |
    | "id" names a record in the audit trail (records without one are numbered
    | by their position). "timestamp" is the field the window measures; it is
    | required when a window is set and read for the audit trail otherwise.
    |
    */
    'id' => 'id',
    'timestamp' => 'created_at',

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    |
    | Every dropped record is written to this table with the survivor it lost
    | to and the key that matched. The record itself is never stored, only its
    | sha256. Set "store_values" to true to also keep the shared normalized
    | value (an email address, for instance) next to each entry.
    |
    */
    'audit' => [
        'enabled' => env('DEDUPE_AUDIT', true),
        'connection' => env('DEDUPE_AUDIT_CONNECTION'),
        'table' => 'dedupe_audit_entries',
        'store_values' => env('DEDUPE_AUDIT_STORE_VALUES', false),
    ],

];
