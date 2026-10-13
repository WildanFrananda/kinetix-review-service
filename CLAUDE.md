<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

## Decisions the code states but cannot explain

### A review is about an order, and order names everyone in it

`ProductReviewService` and `DriverRatingService` take the buyer, the merchant, the courier and the products
from order's `GetOrderDetails`, never from the request. Until 2026-10-13 the request named the merchant and
the courier: one delivered order let a buyer rate every courier in the fleet and file a review under any
merchant. Order sends `merchant_principal_id` and `driver_principal_id` from kinetix-contracts v1.1.0. The
courier is the one order recorded as having delivered, so a rating for an order with no courier on record
is refused.

### Delivered is read from the enum value, not its name

`GrpcOrderClient` compares the status with `OrderStatus::ORDER_STATUS_DELIVERED` and `ORDER_STATUS_COMPLETED`.
It used to compare `OrderStatus::name()` with `"DELIVERED"`, but the name is `"ORDER_STATUS_DELIVERED"`, so
every review and rating in production was refused with 422 while the fake order client, which returned
`"DELIVERED"`, kept the suite green. `tests/Unit/GrpcOrderClientTest.php` maps real protobuf messages for
that reason; a fake that skips the mapping cannot catch it.

### Who wrote a review is not public

`customer_principal_id` and `order_id` are `$hidden` on both models, because the listing routes take no
token. A second review of the same product from the same order, or a second rating of the same order's
courier, is a 409 from the unique index, not a 500.

### generated/ follows the pinned contract

`generated/` is protoc's PHP output for the contract `bin/sync-contracts` pins, committed so the suite runs
without protoc; the image regenerates it from the same pin. The build installs `protobuf-dev` as well as
`protobuf`: order.proto imports `google/protobuf/timestamp.proto`, which the Alpine `protobuf` package does
not carry. Regenerate with the same Alpine protoc whenever the pin moves.
