<?php

namespace App\Domain\Outbound\Exceptions;

use RuntimeException;

class RateLimitExceededException extends RuntimeException {}
