<?php

namespace App\Services\Smtp;

use Symfony\Component\Mailer\Exception\TransportException;

/** The SMTP host can't be used (not found, or a private/local address). A settings problem, not an outage. */
class HostRefused extends TransportException {}
