# Laravel Class Discovery

Laravel Class Discovery is a utility package for Laravel projects that helps you automatically discover PHP classes within specified directories. This can be useful for auto-registering services, event listeners, or other class-based components without manual configuration.

## Features

- Scans directories for PHP classes
- Supports PSR-4 autoloading
- Easy integration with Laravel applications

## Installation

You can install the package via Composer:

```bash
composer require your-vendor/laravel-class-discovery
```

## Usage

Here's a basic example of how to use the Class Discovery package in a Laravel application:

```php
use Schildhain\ClassDiscovery\Facade as ClassDiscovery;

// Discover all classes in the app/Services directory
$classes = ClassDiscovery::in(app_path('Services'))
    ->subclassOf(SomeBaseClass::class) // Optional: filter by subclass
    ->discover();

foreach ($classes as $class) {
    // Do something with each discovered class
}
```

## Configuration

You can publish the configuration file using the following Artisan command:

```bash
php artisan vendor:publish --tag=class-discovery
```

This will create a `config/class-discovery.php` file where you can customize the directories to scan and other options.

## Testing

*to be added*


## License

This package is open-sourced software licensed under the MIT license.

