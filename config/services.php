<?php

declare(strict_types=1);

return [
    "postmark" => [
        "key" => env("POSTMARK_API_KEY"),
    ],

    "resend" => [
        "key" => env("RESEND_API_KEY"),
    ],

    "ses" => [
        "key" => env("AWS_ACCESS_KEY_ID"),
        "secret" => env("AWS_SECRET_ACCESS_KEY"),
        "region" => env("AWS_DEFAULT_REGION", "us-east-1"),
    ],

    "order_service" => [
        "grpc_url" => env("ORDER_SERVICE_GRPC_URL", "kinetix-order-service:50055"),
    ],

    "identity_service" => [
        "grpc_url" => env("IDENTITY_SERVICE_GRPC_URL", "kinetix-identity-service:50052"),
    ],
];
