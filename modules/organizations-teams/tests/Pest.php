<?php

require_once __DIR__.'/Fixtures/OrganizationsTestStubs.php';

use Liberu\Foundation\Organizations\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit/EncryptedTeamSettingsTest.php');
