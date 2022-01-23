# WebSocket Client for PHP

WebSocket Client for PHP >= 7.0  
You can use `WebSocketClient` or `WebSocketCliClient` by environment or your favorite. 

## Usage
### WebSocketClient
For web and CLI environment.

Example:
```php
$client = new WebSocketClient('127.0.0.1', 9502);

$client->connect();
$client->recv(); // hello

$client->push('Some Data');
$body = $client->recv();
var_dump($body);

$client->close();
```

### WebSocketCliClient
For CLI environment only but can use async mode with more performance..  
This class need to install `openswoole` php extension.

> * [PHP Swoole Extention](https://www.php.net/manual/en/book.swoole.php)  
> * [https://openswoole.com/]()

Example:
```php
use Swoole\Coroutine as Co;

Co\run(function() {
    $client = new WebSocketCliClient('127.0.0.1', 9502);
    $client->connect();
    $client->recv(); // hello

    $client->push('Some Data');
    $body = $client->recv();
    var_dump($body);

    $client->close();
});
```

## Referred
> * [paragi/PHP-websocket-client](https://github.com/paragi/PHP-websocket-client)  
> * [Swoole websocket client example](https://github.com/swoole/swoole-src/blob/master/examples/websocket/client.php)

## License
MIT