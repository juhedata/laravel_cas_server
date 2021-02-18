# CAS Server for Laravel

laravel_cas_server is a Laravel package that implements the server part of [CAS protocol](https://apereo.github.io/cas/4.2.x/protocol/CAS-Protocol-Specification.html) v1/v2/v3.

This package works for Laravel >=5.5 .

## Requirements

- PHP >=7.0

## Installation && Usage

- `composer require juhedata/laravel_cas_server`
- `php artisan vendor:publish --provider="JuheData\CAS\CASServerServiceProvider"`
- modify `config/cas.php`, fields in config file are all self-described
- `php artisan migrate`
- make your `App\User` implement `JuheData\CAS\Contracts\Models\UserModel`
- create a class implements `JuheData\CAS\Contracts\TicketLocker`  [TicketLocker示例](https://github.com/juhedata/laravel_cas_server/blob/master/src/Example/CAS/TicketLockerExample.php)
- create a class implements `JuheData\CAS\Contracts\Interactions\UserLogin` [UserLogin示例](https://github.com/juhedata/laravel_cas_server/blob/master/src/Example/CAS/UserLoginExample.php)
- visit `http://your-domain/cas/login` to see the login page (assume that you didn't change the `router.prefix` value in `config/cas.php`)

## Example

If you are looking for an out of box solution of CAS Server powered by PHP, you can check [php_cas_server](https://github.com/leo108/php_cas_server)
