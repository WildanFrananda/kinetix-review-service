<?php

declare(strict_types=1);

use App\Logging\JsonLineFormatter;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;

it("logs through the JSON formatter, to the stream Octane actually forwards", function () {
    $handlers = Log::channel("json")->getLogger()->getHandlers();

    expect($handlers)->toHaveCount(1);
    expect($handlers[0])->toBeInstanceOf(StreamHandler::class);
    expect($handlers[0]->getFormatter())->toBeInstanceOf(JsonLineFormatter::class);
    expect($handlers[0]->getUrl())->toBe("php://stderr");
});

it("makes that channel the default rather than leaving it configured and unused", function () {
    expect(config("logging.channels.stack.channels"))->toBe(["json"]);
});

it("names the logger after the service, not after the deployment environment", function () {
    expect(Log::channel("stack")->getLogger()->getName())->toBe("kinetix-review-service");
    expect(Log::channel("json")->getLogger()->getName())->toBe("kinetix-review-service");
});
