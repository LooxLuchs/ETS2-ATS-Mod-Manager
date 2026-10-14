<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

/** Only controlled, user-readable messages cross the API boundary. */
final class PackageOperationException extends \RuntimeException {}
