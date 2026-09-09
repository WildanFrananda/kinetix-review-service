<?php

declare(strict_types=1);

namespace Tests;

final class MetricsGate {
    public const LEAKED_IDENTIFIER =
        '/[a-z_]+="[^"]*(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}|[0-9a-f]{24,}|@[a-z0-9.-]+\.[a-z]{2,})[^"]*"/i';

    public const UNTEMPLATED_ROUTE = '/route="[^"]*\/[0-9]+/';
}
