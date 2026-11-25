<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Liberu\Foundation\Organizations\Traits\IsTenantModel;

class TenantItem extends Model
{
    use IsTenantModel;

    protected $table = 'org_test_items';

    protected $guarded = [];
}

class TenantChildItem extends Model
{
    use IsTenantModel;

    protected $table = 'org_test_child_items';

    protected $guarded = [];
}
