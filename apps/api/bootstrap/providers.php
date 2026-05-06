<?php

declare(strict_types=1);

use App\Modules\Admin\AdminServiceProvider;
use App\Modules\Agencies\AgenciesServiceProvider;
use App\Modules\Audit\AuditServiceProvider;
use App\Modules\Boards\BoardsServiceProvider;
use App\Modules\Brands\BrandsServiceProvider;
use App\Modules\Campaigns\CampaignsServiceProvider;
use App\Modules\Contracts\ContractsServiceProvider;
use App\Modules\Creators\CreatorsServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Messaging\MessagingServiceProvider;
use App\Modules\Payments\PaymentsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,

    // Domain modules — see docs/02-CONVENTIONS.md §1, §2.2.
    // Order: alphabetical. Modules communicate via events, not service-provider order.
    AdminServiceProvider::class,
    AgenciesServiceProvider::class,
    AuditServiceProvider::class,
    BoardsServiceProvider::class,
    BrandsServiceProvider::class,
    CampaignsServiceProvider::class,
    ContractsServiceProvider::class,
    CreatorsServiceProvider::class,
    IdentityServiceProvider::class,
    MessagingServiceProvider::class,
    PaymentsServiceProvider::class,
];
