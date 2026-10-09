<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** Carries a message meant for the person who uploaded the file. */
final class UploadException extends RuntimeException
{
}
