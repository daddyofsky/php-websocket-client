<?php
/**
 * WebSocket Client using Swoole\Coroutine
 *
 * @remark This class only works in CLI mode because of Swoole\Coroutine
 * @see https://openswoole.com/docs/modules/swoole-coroutine-http-client
 * @example
 *     use Swoole\Coroutine as Co;
 *     Co\run(function() {
 *         $client = new WebSocketCliClient('127.0.0.1', 9502);
 *         $client->connect();
 *         $client->recv(); // hello
 *
 *         $client->push('Some Data');
 *         var_dump($client->recv());
 *     });
 */
namespace daddyofsky;

use Swoole\Coroutine\Http\Client;

class WebSocketCliClient extends Client
{
	/**
	 * constructor
	 *
	 * @param string $host
	 * @param int $port
	 * @param bool $ssl
	 */
	public function __construct($host, $port, $ssl = false)
	{
		parent::__construct($host, $port, $ssl);

		$this->set([
			'timeout' => 10,
		]);
	}

	/**
	 * connect
	 *
	 * @return bool
	 */
	public function connect()
	{
		$this->setHeaders([
			'User-Agent' => 'WebSocketCliClient/1.0',
			'Accept-Encoding' => 'gzip',
		]);

		return $this->upgrade('/');
	}
}

