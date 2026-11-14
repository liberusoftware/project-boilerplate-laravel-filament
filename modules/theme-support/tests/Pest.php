<?php

use Liberu\PackageTestbench\PackageTestCase;

require_once __DIR__.'/Fixtures/ComposerAvailability.php';

pest()->extend(PackageTestCase::class)->in('Unit');
