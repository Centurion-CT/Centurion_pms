<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised by {@see \App\Actions\Integrations\ConnectDokweStorage} when
 * the pre-save live handshake against the operator's Dokwe space
 * fails. Carries the underlying S3 error message verbatim so the
 * operator sees exactly what Dokwe rejected (missing bucket, wrong
 * signature, network timeout).
 */
class DokweStorageConnectionException extends RuntimeException {}
