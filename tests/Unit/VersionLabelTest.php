<?php

declare(strict_types=1);

use App\Observability\VersionLabel;
use Tests\MetricsGate;

it("leaves a version that is already a version alone", function (string $version) {
    expect(VersionLabel::of($version))->toBe($version);
})->with([
    "the shipped default" => "0.0.0-dev",
    "a release tag" => "v1.4.2",
    "a tag and a short sha" => "v1.4.2-9f2c1a4",
    "a date build" => "2026.09.09-14",
]);

it("cannot produce a label the estate's own leak gate would call an identifier", function (string $version) {
    $label = VersionLabel::of($version);

    $body = "kinetix_build_info{service=\"kinetix-review-service\",version=\"{$label}\"} 1\n";

    expect(preg_match(MetricsGate::LEAKED_IDENTIFIER, $body))->toBe(0);
})->with([
    "a full commit sha" => "9f2c1a4be7d035a86c1e5d7b3f0a2c9d81b4e6f7",
    "an uppercase sha" => "9F2C1A4BE7D035A86C1E5D7B3F0A2C9D81B4E6F7",
    "a uuid" => "8f3a1b2c-0000-4000-8000-abcdefabcdef",
    "a tag with a sha glued on" => "v1.4.2+9f2c1a4be7d035a86c1e5d7b3f0a2c9d81b4e6f7",
    "an address someone put in the variable" => "release@kinetix.example.com",
]);

it("says it does not know rather than publishing an empty label", function (string $version) {
    expect(VersionLabel::of($version))->toBe(VersionLabel::UNKNOWN);
})->with([
    "nothing at all" => "",
    "whitespace" => "   ",
    "characters a version is not made of" => "«»",
]);

it("never publishes more than it promises to", function () {
    expect(strlen(VersionLabel::of(str_repeat("a", 500))))->toBe(VersionLabel::MAX_LENGTH);
});
