<?php

declare(strict_types=1);

namespace Review\V1;

use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\ServiceInterface;

interface ReviewEvidenceServiceInterface extends ServiceInterface {
    public const NAME = "review.v1.ReviewEvidenceService";

    public function ReviewsSince(ContextInterface $ctx, ReviewsSinceRequest $in): ReviewsSinceResponse;
}
