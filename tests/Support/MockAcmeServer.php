<?php

namespace LEClient\Tests\Support;

class MockAcmeServer
{
    private static ?self $instance = null;

    private int $port;
    private string $baseUrl;
    private string $stateFile;
    private $process;
    private TempDirectory $tempDir;

    private function __construct()
    {
        $this->tempDir = new TempDirectory('leclient-mock-acme-');
        $this->stateFile = $this->tempDir->file('state.json');
        $this->resetState();

        $this->port = $this->findFreePort();
        $this->baseUrl = 'http://127.0.0.1:' . $this->port;
        $this->start();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            register_shutdown_function([self::class, 'shutdown']);
        }

        return self::$instance;
    }

    public static function shutdown(): void
    {
        if (self::$instance === null) {
            return;
        }

        self::$instance->stop();
        self::$instance->tempDir->cleanup();
        self::$instance = null;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getStateFile(): string
    {
        return $this->stateFile;
    }

    public function resetState(): void
    {
        file_put_contents($this->stateFile, json_encode([
            'accounts' => [],
            'orders' => [],
            'authorizations' => [],
            'challenges' => [],
            'nonces' => [],
        ], JSON_PRETTY_PRINT));
    }

    public function setOrderStatus(string $orderId, string $status): void
    {
        $state = $this->readState();
        if (isset($state['orders'][$orderId])) {
            $state['orders'][$orderId]['status'] = $status;
            $this->writeState($state);
        }
    }

    public function setAuthorizationStatus(string $authKey, string $status): void
    {
        $state = $this->readState();
        if (isset($state['authorizations'][$authKey])) {
            $state['authorizations'][$authKey]['status'] = $status;
            $this->writeState($state);
        }
    }

    private function readState(): array
    {
        return json_decode(file_get_contents($this->stateFile), true);
    }

    private function writeState(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    private function findFreePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }

    private function start(): void
    {
        $router = dirname(__DIR__) . '/MockAcme/router.php';
        $command = sprintf(
            'php -d display_errors=1 -S 127.0.0.1:%d %s',
            $this->port,
            escapeshellarg($router)
        );

        $env = 'MOCK_ACME_STATE=' . escapeshellarg($this->stateFile)
            . ' MOCK_ACME_BASE_URL=' . escapeshellarg($this->baseUrl);

        // The built-in PHP server logs every request to stderr. Routing stdout/stderr
        // to a log file (rather than unread pipes) prevents the OS pipe buffer from
        // filling up and deadlocking the server mid-run.
        $logFile = $this->tempDir->file('server.log');
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];

        $this->process = proc_open($env . ' ' . $command, $descriptors, $pipes, dirname(__DIR__) . '/MockAcme');

        if (!is_resource($this->process)) {
            throw new \RuntimeException('Failed to start mock ACME server');
        }

        fclose($pipes[0]);

        $this->waitUntilReady();
    }

    private function waitUntilReady(): void
    {
        $attempts = 50;
        while ($attempts-- > 0) {
            $handle = curl_init($this->baseUrl . '/directory');
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($handle, CURLOPT_TIMEOUT, 1);
            $response = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

            if ($status === 200 && $response !== false) {
                return;
            }

            usleep(100000);
        }

        throw new \RuntimeException('Mock ACME server failed to start on ' . $this->baseUrl);
    }

    private function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        proc_terminate($this->process);
        proc_close($this->process);
    }
}
