<h1 align="center">Ganesha</h1>

Ganesha is PHP implementation of [Circuit Breaker pattern](http://martinfowler.com/bliki/CircuitBreaker.html) which has multi strategies to avoid cascading failures and supports various storages to record statistics.

<div align="center">

![ganesha](https://ackintosh.github.io/assets/images/ganesha.png)

[![Build Status](https://travis-ci.org/ackintosh/ganesha.svg?branch=master)](https://travis-ci.org/ackintosh/ganesha) [![Coverage Status](https://coveralls.io/repos/github/ackintosh/ganesha/badge.svg?branch=master)](https://coveralls.io/github/ackintosh/ganesha?branch=master) [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/ackintosh/ganesha/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/ackintosh/ganesha/?branch=master) [![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%205.6-8892BF.svg?style=flat-square)](https://php.net/)

</div>

<div align="center">

**If Ganesha is saving your service from system failures, please consider [donating](https://www.patreon.com/ackintosh) to this project's author, [Akihito Nakano](#author), to show your :heart: and support. Thank you!**

<a href="https://www.patreon.com/ackintosh" data-patreon-widget-type="become-patron-button">
<img src="https://c5.patreon.com/external/logo/become_a_patron_button@2x.png" width="160" title="Become a Patron!">
</a>

</div>

---

This is one of the [Circuit Breaker](https://martinfowler.com/bliki/CircuitBreaker.html) implementation in PHP which has been actively developed and production ready - well-tested and well-documented. :muscle:  You can integrate Ganesha to your existing code base easily as Ganesha provides just simple interfaces and [Guzzle Middleware](https://github.com/ackintosh/ganesha#ganesha-heart-guzzle) behaves transparency.

If you have an idea about enhancement, bugfix..., please let me know via [Issues](https://github.com/ackintosh/ganesha/issues). :sparkles:

## Table of contents

- [Ganesha](#ganesha)
- [Table of contents](#table-of-contents)
- [Are you interested?](#are-you-interested)
- [Unveil Ganesha](#unveil-ganesha)
- [Usage](#usage)
- [Strategies](#strategies)
- [Adapters](#adapters)
- [Ganesha :heart: Guzzle](#ganesha-heart-guzzle)
- [Ganesha :heart: OpenAPI Generator](#ganesha-heart-openapi-generator)
- [Run tests](#run-tests)
- [Companies using Ganesha :rocket:](#companies-using-ganesha-rocket)
- [The articles that Ganesha loves :elephant: :sparkles:](#the-articles-that-ganesha-loves-elephant-sparkles)
- [Requirements](#requirements)
- [Build promotion site with Soushi](#build-promotion-site-with-soushi)
- [Author](#author)

## [Are you interested?](#table-of-contents)

[Here](./examples) is an example which shows you how Ganesha behaves when a failure occurs.  
It is easily executable. All you need is Docker. 

## [Unveil Ganesha](#table-of-contents)

```bash
# Install Composer
$ curl -sS https://getcomposer.org/installer | php

# Run the Composer command to install the latest version of Ganesha
$ php composer.phar require ackintosh/ganesha
```

## [Usage](#table-of-contents)

Ganesha provides following simple interfaces. Each method receives a string (named `$service` in example) to identify the service. `$service` will be the service name of the API, the endpoint name or etc. Please remember that Ganesha detects system failure for each `$service`.

```php
$ganesha->isAvailable($service);
$ganesha->success($service);
$ganesha->failure($service);
```

```php
$ganesha = Ackintosh\Ganesha\Builder::build([
    'failureRateThreshold' => 50,
    'adapter'              => new Ackintosh\Ganesha\Storage\Adapter\Redis($redis),
]);

$service = 'external_api';

if (!$ganesha->isAvailable($service)) {
    die('external api is not available');
}

try {
    echo \Api::send($request)->getBody();
    $ganesha->success($service);
} catch (\Api\RequestTimedOutException $e) {
    // If an error occurred, it must be recorded as failure.
    $ganesha->failure($service);
    die($e->getMessage());
}
```

#### Three states of circuit breaker

<img src="https://user-images.githubusercontent.com/1885716/53690408-4a7f3d00-3dad-11e9-852c-0e082b7b9636.png" width="500">

([martinfowler.com : CircuitBreaker](https://martinfowler.com/bliki/CircuitBreaker.html))

Ganesha follows the states and transitions described in the article faithfully. `$ganesha->isAvailable()` returns `true` if the circuit states on `Closed`, otherwise it returns `false`.

#### Subscribe to events in ganesha

- When the circuit state transitions to `Open` the event `Ganesha::EVENT_TRIPPED` is triggered
- When the state back to `Closed` the event `Ganesha::EVENT_CALMED_DOWN ` is triggered

```php
$ganesha->subscribe(function ($event, $service, $message) {
    switch ($event) {
        case Ganesha::EVENT_TRIPPED:
            \YourMonitoringSystem::warn(
                "Ganesha has tripped! It seems that a failure has occurred in {$service}. {$message}."
            );
            break;
        case Ganesha::EVENT_CALMED_DOWN:
            \YourMonitoringSystem::info(
                "The failure in {$service} seems to have calmed down :). {$message}."
            );
            break;
        case Ganesha::EVENT_STORAGE_ERROR:
            \YourMonitoringSystem::error($message);
            break;
        default:
            break;
    }
});
```

#### Disable

If disabled, Ganesha keeps to record success/failure statistics, but Ganesha doesn't trip even if the failure count reached to a threshold.

```php
// Ganesha with Count strategy(threshold `3`).
// $ganesha = ...

// Disable
Ackintosh\Ganesha::disable();

// Although the failure is recorded to storage,
$ganesha->failure($service);
$ganesha->failure($service);
$ganesha->failure($service);

// Ganesha does not trip and Ganesha::isAvailable() returns true.
var_dump($ganesha->isAvailable($service));
// bool(true)
```

#### Reset

Resets the statistics saved in a storage.

```php
$ganesha = Ackintosh\Ganesha\Builder::build([
	// ...
]);

$ganesha->reset();

```

## [Strategies](#table-of-contents)

Ganesha has two strategies which avoids cascading failures.

### Rate (default)

```php
$ganesha = Ackintosh\Ganesha\Builder::build([
    // The interval in time (seconds) that evaluate the thresholds. 
    'timeWindow'            => 30,
    // The failure rate threshold in percentage that changes CircuitBreaker's state to `OPEN`.
    'failureRateThreshold'  => 50,
    // The minimum number of requests to detect failures.
    // Even if `failureRateThreshold` exceeds the threshold,
    // CircuitBreaker remains in `CLOSED` if `minimumRequests` is below this threshold.
    'minimumRequests'       => 10,
    // The interval (seconds) to change CircuitBreaker's state from `OPEN` to `HALF_OPEN`.
    'intervalToHalfOpen'    => 5,
    // The storage adapter instance to store various statistics to detect failures.
    'adapter'               => new Ackintosh\Ganesha\Storage\Adapter\Memcached($memcached),
]);
```

Note about "time window": The Storage Adapter implements either [SlidingTimeWindow](https://github.com/ackintosh/ganesha/blob/master/src/Ganesha/Storage/Adapter/SlidingTimeWindowInterface.php) or [TumblingTimeWindow](https://github.com/ackintosh/ganesha/blob/master/src/Ganesha/Storage/Adapter/TumblingTimeWindowInterface.php). The difference of the implementation comes from constraints of the storage functionalities.

##### [SlidingTimeWindow]

- [SlidingTimeWindow](https://github.com/ackintosh/ganesha/blob/master/src/Ganesha/Storage/Adapter/SlidingTimeWindowInterface.php) implements a time period that stretches back in time from the present. For instance, a SlidingTimeWindow of 30 seconds includes any events that have occurred in the past 30 seconds.
- [Redis adapter](https://github.com/ackintosh/ganesha#redis) and [MongoDB adapter](https://github.com/ackintosh/ganesha#mongodb) implements SlidingTimeWindow.

The details to help us understand visually is shown below:  
(quoted from [Introduction to Stream Analytics windowing functions - Microsoft Azure](https://github.com/MicrosoftDocs/azure-docs/blob/master/articles/stream-analytics/stream-analytics-window-functions.md#sliding-window))

<img height="350" title="slidingtimewindow" src="https://s3-ap-northeast-1.amazonaws.com/ackintosh.github.io/timewindow/sliding-window.png">

##### [TumblingTimeWindow]

- [TumblingTimeWindow](https://github.com/ackintosh/ganesha/blob/master/src/Ganesha/Storage/Adapter/TumblingTimeWindowInterface.php) implements time segments, which are divided by a value of `timeWindow`.
- [Memcached adapter](https://github.com/ackintosh/ganesha#memcached) implements TumblingTimeWindow.

The details to help us understand visually is shown below:  
(quoted from [Introduction to Stream Analytics windowing functions - Microsoft Azure](https://github.com/MicrosoftDocs/azure-docs/blob/master/articles/stream-analytics/stream-analytics-window-functions.md#tumbling-window))

<img height="350" title="tumblingtimewindow" src="https://s3-ap-northeast-1.amazonaws.com/ackintosh.github.io/timewindow/tumbling-window.png">

### Count

If you want use the Count strategy use `Builder::buildWithCountStrategy()` to build an instance.

```php
$ganesha = Ackintosh\Ganesha\Builder::buildWithCountStrategy([
    // The failure count threshold that changes CircuitBreaker's state to `OPEN`.
    // The count will be increased if `$ganesha->success()` is called,
    // or will be decreased if `$ganesha->failure()` is called.
    'failureCountThreshold' => 100,
    // The interval (seconds) to change CircuitBreaker's state from `OPEN` to `HALF_OPEN`.
    'intervalToHalfOpen'    => 5,
    // The storage adapter instance to store various statistics to detect failures.
    'adapter'               => new Ackintosh\Ganesha\Storage\Adapter\Memcached($memcached),
]);
```

## [Adapters](#table-of-contents)

### Redis

Redis adapter requires [phpredis](https://github.com/phpredis/phpredis) or [Predis](https://github.com/nrk/predis) client instance. The example below is using [phpredis](https://github.com/phpredis/phpredis).

```php
$redis = new \Redis();
$redis->connect('localhost');
$adapter = new Ackintosh\Ganesha\Storage\Adapter\Redis($redis);

$ganesha = Ackintosh\Ganesha\Builder::build([
    'adapter' => $adapter,
]);
```

### Memcached

Memcached adapter requires [memcached](https://github.com/php-memcached-dev/php-memcached/) (NOT memcache) extension.

```php
$memcached = new \Memcached();
$memcached->addServer('localhost', 11211);
$adapter = new Ackintosh\Ganesha\Storage\Adapter\Memcached($memcached);

$ganesha = Ackintosh\Ganesha\Builder::build([
    'adapter' => $adapter,
]);
```

### MongoDB

MongoDB adapter requires [mongodb](https://github.com/mongodb/mongo-php-library) extension.

```php
$manager = new \MongoDB\Driver\Manager('mongodb://localhost:27017/');
$adapter = new Ackintosh\Ganesha\Storage\Adapter\MongoDB($manager);
$configuration = new Configuration(['dbName' => 'ganesha', 'collectionName' => 'ganeshaCollection']);

$adapter->setConfiguration($configuration);
$ganesha = Ackintosh\Ganesha\Builder::build([
    'adapter' => $adapter,
]);
```

## [Ganesha :heart: Guzzle](#table-of-contents)

If you using [Guzzle](https://github.com/guzzle/guzzle) (v6 or higher), [Guzzle Middleware](http://docs.guzzlephp.org/en/stable/handlers-and-middleware.html) powered by Ganesha makes it easy to integrate Circuit Breaker to your existing code base.

```php
use Ackintosh\Ganesha\Builder;
use Ackintosh\Ganesha\GuzzleMiddleware;
use Ackintosh\Ganesha\Exception\RejectedException;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

$ganesha = Builder::build([
    'timeWindow'           => 30,
    'failureRateThreshold' => 50,
    'minimumRequests'      => 10,
    'intervalToHalfOpen'   => 5,
    'adapter'              => $adapter,
]);
$middleware = new GuzzleMiddleware($ganesha);

$handlers = HandlerStack::create();
$handlers->push($middleware);

$client = new Client(['handler' => $handlers]);

try {
    $client->get('http://api.example.com/awesome_resource');
} catch (RejectedException $e) {
    // If the circuit breaker is open, RejectedException will be thrown.
}
```

### How does Guzzle Middleware determine the `$service`?

As documented in [Usage](https://github.com/ackintosh/ganesha#usage), Ganesha detects failures for each `$service`. Below, We will show you how Guzzle Middleware determine `$service` and how we specify `$service` explicitly.

By default, the host name is used as `$service`.


```php
// In the example above, `api.example.com` is used as `$service`.
$client->get('http://api.example.com/awesome_resource');
```

You can also specify `$service` via a option passed to client, or request header. If both are specified, the option value takes precedence.

```php
// via constructor argument
$client = new Client([
    'handler' => $handlers,
    // 'ganesha.service_name' is defined as ServiceNameExtractor::OPTION_KEY
    'ganesha.service_name' => 'specified_service_name',
]);

// via request method argument
$client->get(
    'http://api.example.com/awesome_resource',
    [
        'ganesha.service_name' => 'specified_service_name',
    ]
);

// via request header
$request = new Request(
    'GET',
    'http://api.example.com/awesome_resource',
    [
        // 'X-Ganesha-Service-Name' is defined as ServiceNameExtractor::HEADER_NAME
        'X-Ganesha-Service-Name' => 'specified_service_name'
    ]
);
$client->send($request);
```

Alternatively, you can apply your own rules by implementing a class that implements the `ServiceNameExtractorInterface`.

```php
use Ackintosh\Ganesha\GuzzleMiddleware\ServiceNameExtractorInterface;
use Psr\Http\Message\RequestInterface;

class SampleExtractor implements ServiceNameExtractorInterface
{
    /**
     * @override
     */
    public function extract(RequestInterface $request, array $requestOptions)
    {
        // We treat the combination of host name and HTTP method name as $service.
        return $request->getUri()->getHost() . '_' . $request->getMethod();
    }
}

// ---

$ganesha = Builder::build([
    // ...
]);
$middleware = new GuzzleMiddleware(
    $ganesha,
    // Pass the extractor as an argument of GuzzleMiddleware constructor.
    new SampleExtractor()
);
```

## [Ganesha :heart: OpenAPI Generator](#table-of-contents)

PHP client generated by [OpenAPI Generator](https://github.com/OpenAPITools/openapi-generator) is using Guzzle as HTTP client and as we mentioned as [Ganesha :heart: Guzzle](https://github.com/ackintosh/ganesha#ganesha-heart-guzzle), Guzzle Middleware powered by Ganesha is ready. So it is easily possible to integrate Ganesha and the PHP client generated by OpenAPI Generator in a smart way as below.

```php
// For details on how to build middleware please see https://github.com/ackintosh/ganesha#ganesha-heart-guzzle
$middleware = new GuzzleMiddleware($ganesha);

// Set the middleware to HTTP client.
$handlers = HandlerStack::create();
$handlers->push($middleware);
$client = new Client(['handler' => $handlers]);

// Just pass the HTTP client to the constructor of API class.
$api = new PetApi($client);

try {
    // Ganesha is working in the shadows! The result of api call is monitored by Ganesha.
    $api->getPetById(123);
} catch (RejectedException $e) {
    awesomeErrorHandling($e);
}
```

## [Run tests](#table-of-contents)

We can run unit tests on a Docker container, so it is not necessary to install the dependencies in your machine.

```bash
# Start redis, memcached server
$ docker-compose up 

# Run tests in container
$ docker-compose run --rm -w /tmp/ganesha -u ganesha client vendor/bin/phpunit
```

## [Companies using Ganesha :rocket:](#table-of-contents)

Here are some companies using Ganesha in production. To add your company to the list, please visit [README.md](https://github.com/ackintosh/ganesha/blob/master/README.md) and click on the icon to edit the page or let me know via [issues](https://github.com/ackintosh/ganesha/issues)/[twitter](https://twitter.com/NAKANO_Akihito). 

- [APISHIP LLC](https://apiship.ru)

## [The articles that Ganesha loves :elephant: :sparkles:](#table-of-contents)

Here are some articles Ganesha has been introduced!

- [PHP Weekly. Archive. August 1, 2019. News, Articles and more all about PHP](http://www.phpweekly.com/archive/2019-08-01.html)
- [PHP Annotated – July 2019 | PhpStorm Blog](https://blog.jetbrains.com/phpstorm/2019/07/php-annotated-july-2019/)
- [Безопасное взаимодействие в распределенных системах / Блог компании Badoo / Хабр](https://habr.com/ru/company/badoo/blog/413555/)
- [A Semana PHP - Edição Nº229 | Revue](https://www.getrevue.co/profile/asemanaphp/issues/a-semana-php-edicao-n-229-165581)
- [PHP DIGEST #12: NEWS & TOOLS (JANUARY 1 - JANUARY 14, 2018)](https://www.zfort.com/blog/php-digest-january-14-2018)

## [Requirements](#table-of-contents)

- Ganesha supports PHP 5.6 or higher.
  - Note: We are planning to drop the support for PHP 5.6. Please see [here](https://github.com/ackintosh/ganesha/issues/43) to track the status of the issue.
- An extension or client library which is used by [the storage adapter](https://github.com/ackintosh/ganesha#adapters) you've choice will be required. Please check the [Adapters](https://github.com/ackintosh/ganesha#adapters) section for details.

## [Build promotion site with Soushi](#table-of-contents)

https://ackintosh.github.io/ganesha/  

The promotion site is built with [Soushi](https://github.com/kentaro/soushi), which is the site generator powered by PHP.

```bash
$ docker-compose run --rm client soushi build docs

# The site will be generate into `docs` directory and let's confirm your changes.
$ open docs/index.html
```

## [Author](#table-of-contents)

**Ganesha** &copy; ackintosh, Released under the [MIT](./LICENSE) License.  
Authored and maintained by ackintosh

> GitHub [@ackintosh](https://github.com/ackintosh) / Twitter [@NAKANO_Akihito](https://twitter.com/NAKANO_Akihito) / [Blog (ja)](https://ackintosh.github.io/)
