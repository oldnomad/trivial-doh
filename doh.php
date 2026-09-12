<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 Alec Kojaev <alec@kojaev.name>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace TrivialDoH;

const RAW_CONTENT_TYPE = 'application/dns-message';
const MAX_MESSAGE_SIZE = 65536; // Theoretical maximum for TCP
const DNS_PORT = '53';

const CONFIG_FILE = './doh.json';
const CONFIG_DEFAULT = [
    'debug'   => 0,
    'timeout' => 5,
    'servers' => [
        '8.8.8.8', '[2001:4860:4860::8888]',
        '8.8.4.4', '[2001:4860:4860::8844]',
    ],
];

final class Config
{
    /**
     * Flag for CLI mode.
     */
    public readonly bool $isCli;

    /**
     * Request time-out (seconds).
     */
    public readonly int $timeout;

    /**
     * List of servers.
     * @var array<string>
     */
    public readonly array $servers;

    /**
     * Debug level.
     */
    public readonly int $debug;

    public function __construct()
    {
        $this->isCli = (http_response_code() === false);
        $cfg = self::readConfig();
        $this->timeout = intval($cfg['timeout']);
        /** @var array<string> */
        $this->servers = $cfg['servers'];
        $this->debug   = intval($cfg['debug']);
    }

    /**
     * Read configuration, if any.
     *
     * @return array Configuration parameters.
     */
    private static function readConfig(): array
    {
        $cfg = CONFIG_DEFAULT;
        if (!is_readable(CONFIG_FILE)) {
            return $cfg;
        }
        $cfgFile = file_get_contents(CONFIG_FILE);
        if ($cfgFile === false) {
            return $cfg;
        }
        $cfgData = json_decode($cfgFile, true);
        if (!is_array($cfgData)) {
            return $cfg;
        }
        $cfg = $cfgData + $cfg;
        return $cfg;
    }

    /**
     * Choose a server to use.
     *
     * If APCu is enabled, this method follows round-robin strategy.
     * Otherwise, a random server is chosen.
     *
     * @return string Server to use.
     */
    public function chooseServer(): string
    {
        $len = count($this->servers);
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            apcu_add('doh.counter', -1);
            $index = apcu_inc('doh.counter');
            if ($index === false) {
                apcu_store('doh.counter', 0);
                $index = 0;
            }
            $index %= $len;
            if ($index < 0) {
                $index += $len;
            }
        } else {
            $index = rand(0, $len - 1);
        }
        return $this->servers[$index] . ':' . DNS_PORT;
    }

    /**
     * Output a message (only in CLI mode).
     *
     * @param string $fmt     Text format (printf-like).
     * @param mixed  ...$args Format arguments.
     *
     * @return void
     */
    public function info(string $fmt, mixed ...$args): void
    {
        if (!$this->isCli) {
            $this->debug(2, $fmt, ...$args);
            return;
        }
        /** @psalm-suppress MixedArgument */
        printf($fmt . "\n", ...$args);
    }

    /**
     * Output an error message.
     *
     * @param boolean $important Flag to output message in the error log.
     * @param string  $fmt       Text format (printf-like).
     * @param mixed   ...$args   Format arguments.
     *
     * @return void
     */
    public function error(bool $important, string $fmt, mixed ...$args): void
    {
        /** @psalm-suppress MixedArgument */
        $msg = sprintf('ERROR: ' . $fmt, ...$args);
        if ($this->isCli) {
            print $msg . "\n";
        } else {
            if ($important) {
                error_log($msg);
            } else {
                $this->debug(1, '%s', $msg);
            }
        }
    }

    /**
     * Output a debug message.
     *
     * @param integer $level   Message level.
     * @param string  $fmt     Text format (printf-like).
     * @param mixed   ...$args Format arguments.
     *
     * @return void
     */
    public function debug(int $level, string $fmt, mixed ...$args): void
    {
        if ($this->debug < $level) {
            return;
        }
        $msg = sprintf('DEBUG[%d]: ' . $fmt, $level, ...$args);
        if ($this->isCli) {
            print $msg . "\n";
        } else {
            error_log($msg);
        }
    }
}

final class Server
{
    public function __construct(
        /**
         * Server configuration.
         */
        private readonly Config  $config,
        /**
         * Request method.
         */
        private readonly string  $method,
        /**
         * Request parameters.
         * @var array<string|array>
         */
        private readonly array   $param,
        /**
         * Input content type.
         */
        private readonly ?string $inputType,
        /**
         * Input stream.
         */
        private readonly string  $input,
    ) {
        // Do nothing
    }

    /**
     * Process error.
     *
     * @param integer $code HTTP error code.
     * @param string  $note Error note.
     *
     * @return void
     */
    private function processError(int $code, string $note): void
    {
        $this->config->error(false, 'Request parsing failed, status %d, %s', $code, $note);
        if (!$this->config->isCli) {
            http_response_code($code);
        }
    }

    /**
     * Parse DoH request.
     *
     * @return string|null Raw DNS request bytes, or `null` on error.
     */
    public function parseRequest(): ?string
    {
        switch ($this->method) {
            case 'GET':
                $data = $this->param['dns'] ?? null;
                if ($data === null || !is_string($data) || $data === '') {
                    $this->processError(400, 'no parameter');
                    return null;
                }
                $data = strtr($data, '-_', '+/');
                $pad  = strlen($data) % 4;
                if ($pad !== 0) {
                    $data = $data . str_repeat('=', 4 - $pad);
                }
                $data = base64_decode($data, true);
                if ($data === false) {
                    $this->processError(400, 'decode error');
                    return null;
                }
                return $data;
            case 'POST':
                if ($this->inputType === null) {
                    $this->processError(400, 'no content type');
                    return null;
                }
                if ($this->inputType !== RAW_CONTENT_TYPE) {
                    $this->processError(415, 'invalid content type ' . $this->inputType);
                    return null;
                }
                $data = file_get_contents($this->input);
                if ($data === false) {
                    $this->processError(400, 'input read error');
                    return null;
                }
                return $data;
            default:
                $this->processError(405, 'unrecognized method ' . $this->method);
                return null;
        }
    }

    /**
     * Attempt to forward DNS message to a real DNS server.
     *
     * @param string $proto   Protocol ('udp' or 'tcp').
     * @param string $server  Real DNS server (host and port).
     * @param string $request DNS request.
     *
     * @return string|null DNS response, or `null` on error.
     */
    private function forwardRequestInternal(string $proto, string $server, string $request): ?string
    {
        $addr = $proto . '://' . $server;
        $sock = stream_socket_client($addr, $err, $msg, $this->config->timeout);
        if ($sock === false) {
            $this->config->error(true, 'DNS server "%s", protocol %s: open error %d: %s', $server, $proto, $err, $msg);
            return null;
        }
        stream_set_timeout($sock, $this->config->timeout);
        $data = $request;
        if ($proto === 'tcp') {
            $data = pack('n', strlen($data)) . $data;
        }
        if (fwrite($sock, $data) === false) {
            $this->config->error(false, 'DNS server "%s", protocol %s: write error', $server, $proto);
            fclose($sock);
            return null;
        }
        $resp = fread($sock, MAX_MESSAGE_SIZE);
        fclose($sock);
        if ($resp === false || $resp === '') {
            $this->config->error(false, 'DNS server "%s", protocol %s: empty response', $server, $proto);
            return null;
        }
        if ($proto === 'tcp') {
            $len = strlen($resp);
            if ($len < 2) {
                $this->config->error(false, 'DNS server "%s", protocol %s: response is too short', $server, $proto);
                return null;
            }
            $len -= 2;
            $pack = unpack('n', $resp);
            if ($pack === false) {
                $this->config->error(false, 'DNS server "%s", protocol %s: response length unpack error', $server, $proto);
                return null;
            }
            /** @var integer */
            $rlen = $pack[1];
            $resp = substr($resp, 2);
            if ($rlen !== $len) {
                $this->config->error(false, 'DNS server "%s", protocol %s: response length mismatch (%d <> %d)', $server, $proto, $rlen, $len);
                return null;
            }
        }
        return $resp;
    }

    /**
     * Forward DNS message to a real DNS server.
     *
     * @param string $request DNS request.
     *
     * @return string|null DNS response, or `null` on error.
     */
    public function forwardRequest(string $request): ?string
    {
        $this->config->info('REQUEST: %s', bin2hex($request));
        if ($request === '') {
            $this->processError(400, 'empty request');
            return null;
        }
        $server = $this->config->chooseServer();
        $this->config->info('SERVER: %s', $server);
        $resp = $this->forwardRequestInternal('udp', $server, $request);
        if ($resp === null) {
            $this->processError(500, 'UDP request failure');
            return null;
        }
        if (strlen($resp) >= 3) {
            $flag = ord($resp[2]);
            if (($flag & 0x02) !== 0) {
                $this->config->info('UDP response is truncated, retrying TCP');
                $resp = $this->forwardRequestInternal('tcp', $server, $request);
                if ($resp === null) {
                    $this->processError(500, 'TCP request failure');
                }
            }
        }
        return $resp;
    }

    /**
     * Send response to client.
     *
     * @param string $response DNS response.
     *
     * @return void
     */
    public function sendResponse(string $response): void
    {
        $this->config->info('RESPONSE: %s', bin2hex($response));
        if ($this->config->isCli) {
            return;
        }
        header('Content-Type: ' . RAW_CONTENT_TYPE);
        print $response;
    }

    /**
     * Process the request.
     *
     * @return void
     */
    public function process(): void
    {
        $request = $this->parseRequest();
        if (is_null($request)) {
            return;
        }
        $response = $this->forwardRequest($request);
        if (is_null($response)) {
            return;
        }
        $this->sendResponse($response);
    }
}

// Main procedure
$config = new Config();
if ($config->isCli) {
    if ($argc < 2) {
        printf("Usage: %s GET|POST [<base64url-data>]\n", $argv[0]);
        exit;
    }
    $server = new Server($config, $argv[1], ['dns' => $argv[2] ?? ''], RAW_CONTENT_TYPE, 'php://stdin');
} else {
    $server = new Server($config, $_SERVER['REQUEST_METHOD'] ?? '-', $_GET, $_SERVER['CONTENT_TYPE'] ?? null, 'php://input');
}
$server->process();
