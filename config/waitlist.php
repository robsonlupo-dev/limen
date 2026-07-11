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

];
