<?php
/**
 * WebSocket Client
 *
 * @refer https://github.com/paragi/PHP-websocket-client
 * @refer https://github.com/swoole/swoole-src/blob/master/tests/include/api/swoole_websocket_server/websocket_client.php
 */
namespace daddyofsky;

use RuntimeException;

class WebSocketClient
{
	/** @var string */
	protected $host;

	/** @var int */
	protected $port;

	/** @var bool */
	protected $ssl;

	/** @var resource */
	protected $sp;

	/** @var array */
	protected $options = [
		'timeout' => 10,
	];

	/** @var array */
	protected $headers = [];

	/**
	 * constructor
	 *
	 */
	public function __construct($host, $port, $ssl = false)
	{
		$this->host = $host;
		$this->port = $port;
		$this->ssl  = $ssl;
	}

	/**
	 * set options
	 * timeout option only for now
	 *
	 * @param array $options
	 */
	public function set($options)
	{
		$this->options = array_merge($this->options, $options);
	}

	/**
	 * set headers
	 *
	 * @param array $headers
	 */
	public function setHeaders($headers)
	{
		$this->headers = array_merge($this->headers, $headers);
	}

	/**
	 * connect to websocket server
	 */
	public function connect()
	{
		$server = $this->host . ':' . $this->port;
		if ($this->ssl) {
			$server = 'ssl://' . $server;
		}
		$timeout = $this->options['timeout'] ?: 10;

		$sp = stream_socket_client($server, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
		if (!$sp) {
			throw new RuntimeException(sprintf('[%s] %s', $errno, $errstr));
		}

		// send header
		$key    = base64_encode(openssl_random_pseudo_bytes(16));
		$header = "GET / HTTP/1.1\r\n"
				  . "Host: $this->host\r\n"
				  . "pragma: no-cache\r\n"
				  . "Upgrade: WebSocket\r\n"
				  . "Connection: Upgrade\r\n"
				  . "Sec-WebSocket-Key: $key\r\n"
				  . "Sec-WebSocket-Version: 13\r\n";

		foreach ($this->headers as $k => $v) {
			if (is_string($k)) {
				$header .= $k . ': ' . $v . "\r\n";
			} else {
				$header .= $v . "\r\n";
			}
		}
		$header .= "\r\n";

		if (!fwrite($sp, $header)) {
			throw new RuntimeException('Fail to send header to websocket server');
		}

		// response header
		// only header! body should be used at next recv()
		$response_header = '';
		while (trim($line = fgets($sp))) {
			$response_header .= $line;
		}
		if (!$response_header || strpos($response_header, ' 101 ') === false || stripos($response_header, 'Sec-WebSocket-Accept: ') === false) {
			throw new RuntimeException('Fail to upgrade connection to websocket');
		}

		$this->sp = $sp;
	}

	/**
	 * close connection
	 */
	public function close()
	{
		$this->send('', 'close');
	}

	/**
	 * push data to server
	 * alias of send()
	 *
	 * @param string $data
	 * @param string $type
	 * @param bool $masked
	 * @param bool $final
	 * @return false|int
	 */
	public function push($data, $type = 'text', $masked = true, $final = true)
	{
		return $this->send($data, $type, $masked, $final);
	}

	/**
	 * send data to server
	 *
	 * @param string $data
	 * @param string $type
	 * @param bool $masked
	 * @param bool $final
	 * @return false|int
	 */
	public function send($data, $type = 'text', $masked = true, $final = true)
	{
		return fwrite($this->sp, $this->encode($data, $type, $masked, $final));
	}

	/**
	 * recv data from server
	 *
	 * @return false|string
	 */
	public function recv()
	{
		stream_set_timeout($this->sp, 0, 50000); // 0.05 second
		$retry_count = 5; // 0.25 second

		$data = '';
		$retry = 0;
		$final = 0;

		do {
			$header = fread($this->sp, 2);
			if (!$header) {
				if (++$retry < $retry_count) {
					continue;
				}
				return false;
			}

			$opcode = ord($header[0]) & 0x0F;
			$final  = ord($header[0]) & 0x80;
			$masked = ord($header[1]) & 0x80;
			$length = ord($header[1]) & 0x7F;

			// close
			if ($opcode === 8) {
				fclose($this->sp);
				return '';
			}

			// ping
			if ($opcode === 9) {
				fwrite($this->sp, chr(0x8A) . chr(0x80) . pack('N', rand(1, 0x7FFFFFFF)));
				continue;
			}

			// length
			if ($length >= 0x7E) {
				$extra_length = 2;
				if ($length == 0x7F) {
					$extra_length = 8;
				}

				$length = 0;
				$header = fread($this->sp, $extra_length);
				for ($i = 0; $i < $extra_length; $i++) {
					$length += ord($header[$i]) << ($extra_length - $i - 1) * 8;
				}
			}

			// mask key
			$mask = $masked ? fread($this->sp, 4) : '';

			$frame_data = '';
			while ($length > 0) {
				$frame = fread($this->sp, $length);
				$length -= strlen($frame);
				$frame_data .= $frame;
			}

			if ($masked) {
				$data .= $this->mask($frame_data, $mask);
			} else {
				$data .= $frame_data;
			}

		} while (!$final);

		return $data;
	}

	/**
	 * encode data by hybi10 protocol
	 *
	 * @param string $data
	 * @param string $type
	 * @param bool $masked
	 * @param bool $final
	 * @return string
	 */
	public function encode($data, $type = 'text', $masked = true, $final = true)
	{
		static $array_mode = [
			'text'   => 0x01, // 1, 129
			'binary' => 0x02, // 2, 130
			'close'  => 0x08, // 8, 136
			'ping'   => 0x09, // 9, 127
			'pong'   => 0x0A, // 10, 138
		];

		// alias
		if ($type === 'file' || $type === 'bin') {
			$type = 'binary';
		}

		// mode
		$flag = $array_mode[$type] ?? $array_mode['text'];
		$flag = $final ? chr(0x80 | $flag) : chr($flag);
		$header = $flag;

		// length
		$length = strlen($data);
		if ($length < 126) {
			$flag = chr(0x80 | $length);
		} else if ($length < 0xFFFF) {
			$flag = chr(0x80 | 126) . pack('n', $length);
		} else {
			$flag = chr(0x80 | 127) . pack('N', 0) . pack('N', strlen($data));
		}
		$header .= $flag;

		// mask
		if ($masked) {
			$mask = pack('N', rand(1, 0x7FFFFFFF));
			$header .= $mask;

			$data = $this->mask($data, $mask);
		}

		return $header . $data;
	}

	/**
	 * mask data
	 *
	 * @param string $data
	 * @param string $mask
	 * @return string
	 */
	protected function mask($data, $mask)
	{
		$length = strlen($data);
		for ($i = 0; $i < $length; $i++) {
			$data[$i] = $data[$i] ^ $mask[$i % 4];
		}
		return $data;
	}
}