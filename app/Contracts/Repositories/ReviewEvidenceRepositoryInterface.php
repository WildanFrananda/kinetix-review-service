<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Evidence\LogPosition;
use App\Evidence\ReviewEvidencePage;

interface ReviewEvidenceRepositoryInterface {
    public function since(LogPosition $after, int $limit): ReviewEvidencePage;
}
