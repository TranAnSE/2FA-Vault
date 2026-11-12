<?php
require __DIR__ . '/../vendor/autoload.php';
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
$sec64 = str_repeat('1234567890', 6) . '1234';
echo 'len:', strlen($sec64), ' b32:', Base32::encodeUpper($sec64), PHP_EOL;
$g = TOTP::create(Base32::encodeUpper($sec64), 30, 'sha512', 8);
echo 'sha512 t59:', $g->at(59), ' t1111111109:', $g->at(1111111109), PHP_EOL;
$g256 = TOTP::create(Base32::encodeUpper(str_repeat('1234567890', 3) . '12'), 30, 'sha256', 8);
echo 'sha256 t59:', $g256->at(59), ' t1111111109:', $g256->at(1111111109), PHP_EOL;
