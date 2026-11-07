<?php

namespace App\Services\Deliverability;

/** A broken SPF record (permerror), worded for the customer. */
class SpfError extends \RuntimeException {}
