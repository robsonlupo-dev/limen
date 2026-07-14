<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disposable / throwaway email domains
    |--------------------------------------------------------------------------
    |
    | Signups from these domains are rejected at validation (see
    | App\Rules\NotDisposableEmailDomain, wired into WaitlistWebRequest). They
    | are the vector for referral farming — a fraudster spins up throwaway
    | inboxes to self-confirm invites. Matching is exact on the lowercased host.
    | Keep alphabetical, one per line, for easy diffing.
    |
    */

    'disposable_email_domains' => [
        '10minutemail.com',
        '33mail.com',
        'burnermail.io',
        'discard.email',
        'discardmail.com',
        'disposablemail.com',
        'dispostable.com',
        'easytrashmail.com',
        'emailondeck.com',
        'fakeinbox.com',
        'fakemail.net',
        'getairmail.com',
        'getnada.com',
        'gishpuppy.com',
        'grr.la',
        'guerrillamail.com',
        'guerrillamailblock.com',
        'inboxkitten.com',
        'jetable.fr.nf',
        'luxusmail.org',
        'mailboxy.fun',
        'mailcatch.com',
        'mailde.de',
        'maildrop.cc',
        'mailexpire.com',
        'mailinator.com',
        'mailnesia.com',
        'mailnull.com',
        'mailpoof.com',
        'mailsac.com',
        'mailtemp.info',
        'minuteinbox.com',
        'moakt.com',
        'mohmal.com',
        'mt2015.com',
        'mytemp.email',
        'nada.email',
        'nospamfor.us',
        'objectmail.com',
        'sharklasers.com',
        'spam4.me',
        'spambox.us',
        'spamevader.com',
        'spamfree24.org',
        'spamgap.com',
        'spamgourmet.com',
        'spamgourmet.net',
        'spamspot.com',
        'spamthisplease.com',
        'temp-mail.org',
        'tempemail.co',
        'tempinbox.com',
        'tempmail.com',
        'tempmail.dev',
        'tempmail.ninja',
        'tempmailaddress.com',
        'tempmailo.com',
        'tempr.email',
        'throwam.com',
        'throwawaymail.com',
        'trashmail.at',
        'trashmail.com',
        'trashmail.io',
        'trashmail.me',
        'yopmail.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Nurturing drip sequence
    |--------------------------------------------------------------------------
    |
    | The 7-email sequence sent to CONFIRMED waitlist entries (anchored on
    | confirmed_at — an unconfirmed entry never enters the drip). Each step is
    | dispatched at most once per entry, tracked idempotently in the
    | waitlist_email_log table (see WaitlistNurtureService).
    |
    | Tuning the cadence is data-only: edit `after_days`, or flip `enabled` to
    | pause a step without touching code. `key` is the email_key persisted in the
    | log and the blade view name (emails.waitlist.nurture.{key}); keep it stable
    | once a step has shipped, or already-sent entries would receive it again.
    |
    */

    // The drip only enrolls entries confirmed AT OR AFTER this instant. It guards
    // against a backfill blast on first deploy: without a floor, every already-
    // confirmed entry would receive all past-due steps at once (and could trip the
    // Resend rate limit). Set WAITLIST_NURTURE_START_AT to the activation date on
    // launch. null enrolls everyone — only safe on a fresh/empty waitlist.
    'nurture_start_at' => env('WAITLIST_NURTURE_START_AT'),

    // Safety throttle: max emails queued per step per run. The command runs hourly
    // and is idempotent, so anything over the cap simply goes out the next run.
    // null = no cap.
    'nurture_max_per_run' => env('WAITLIST_NURTURE_MAX_PER_RUN', 200),

    'nurture' => [
        ['key' => 'nurture_1', 'after_days' => 1,  'enabled' => true],
        ['key' => 'nurture_2', 'after_days' => 3,  'enabled' => true],
        ['key' => 'nurture_3', 'after_days' => 7,  'enabled' => true],
        ['key' => 'nurture_4', 'after_days' => 14, 'enabled' => true],
        ['key' => 'nurture_5', 'after_days' => 21, 'enabled' => true],
        ['key' => 'nurture_6', 'after_days' => 30, 'enabled' => true],
        ['key' => 'nurture_7', 'after_days' => 45, 'enabled' => true],
    ],

];
