<?php

declare(strict_types=1);

it("returns ok status on healthcheck endpoint", function () {
    $response = $this->getJson("/api/health");

    $response->assertStatus(200)
        ->assertJson([
            "status" => "ok",
            "service" => "kinetix-review-service",
        ]);
});
