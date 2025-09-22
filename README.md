# `do-functions-php-send-email`

[![Check Code](https://github.com/ElefantOne/do-functions-php-send-email/actions/workflows/check-code.yml/badge.svg)](https://github.com/ElefantOne/do-functions-php-send-email/actions/workflows/check-code.yml)

A serverless function to send emails with attachments (optional).

## Installation

```shell
cd packages/default/send-email

composer install
```

## Code checking and formatting

We use [mago](https://github.com/carthage-software/mago) for this.

```shell
just lint
just analyze

just fmt
```

## Deploy

```shell
doctl serverless deploy . --remote-build
```

## Usage

```shell
php test.php
```
