<?php

/** Dump phpunit result-cache defects (kind -> test ids). */
$c    = json_decode(file_get_contents(__DIR__ . '/../.phpunit.result.cache'), true);
$data = $c['defects'] ?? [];

foreach ($data as $kind => $items) {
    echo strtoupper((string) $kind), PHP_EOL;

    if (! is_array($items)) {
        echo '  ', var_export($items, true), PHP_EOL;

        continue;
    }

    foreach ($items as $id => $v) {
        echo '  ', (string) $id, PHP_EOL;
    }
}
