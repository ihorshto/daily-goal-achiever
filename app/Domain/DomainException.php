<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** A business-rule violation whose message is safe to show the user/model. */
final class DomainException extends RuntimeException {}
