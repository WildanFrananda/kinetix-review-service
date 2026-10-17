<?php

declare(strict_types=1);

use App\Grpc\ReviewEvidenceService;
use Illuminate\Contracts\Console\Kernel;
use Review\V1\ReviewEvidenceServiceInterface;
use Spiral\RoadRunner\GRPC\Server;
use Spiral\RoadRunner\Worker;

require __DIR__ . "/vendor/autoload.php";

$app = require __DIR__ . "/bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();

$server = new Server;
$server->registerService(ReviewEvidenceServiceInterface::class, $app->make(ReviewEvidenceService::class));
$server->serve(Worker::create());
