#!/bin/php
<?php
/**
 * PHP-FPM Status Page Parser
 * 
 * Connects to PHP-FPM via HTTP, Unix socket, or TCP socket (direct FastCGI)
 * and displays a table/scoreboard of running processes.
 * 
 * Usage: php fpm-status.php [options] [target]
 */

class FastCGIClient
{
    private const FCGI_VERSION_1 = 1;
    private const FCGI_BEGIN_REQUEST = 1;
    private const FCGI_END_REQUEST = 3;
    private const FCGI_PARAMS = 4;
    private const FCGI_STDIN = 5;
    private const FCGI_STDOUT = 6;
    private const FCGI_STDERR = 7;
    private const FCGI_RESPONDER = 1;
    
    private $socket;
    private int $requestId = 1;
    
    public function __construct(string $target)
    {
        if (str_starts_with($target, '/')) {
            // Unix socket
            $this->socket = @stream_socket_client("unix://{
            $target}", $errno, $errstr, 10);
        } else {
            // TCP socket (host:port)
            if (!str_contains($target, ':')) {
                $target .= ':9000';
            }
            $this->socket = @stream_socket_client("tcp://{
            $target}", $errno, $errstr, 10);
        }
        
        if (!$this->socket) {
            throw new RuntimeException("Failed to connect to FastCGI: {$errstr} ({$errno})");
        }
        
        stream_set_timeout($this->socket, 10);
    }
    
    public function __destruct()
    {
        if ($this->socket) {
            fclose($this->socket);
        }
    }
    
    public function request(string $statusPath, bool $full = true, string $format = ''): string
    {
        $query = $full ? 'full' : '';
        if ($format) {
            $query .= ($query ? '&' : '') . $format;
        }
        
        // FPM status page requires these specific params
        $params = [
            'GATEWAY_INTERFACE' => 'FastCGI/1.0',
            'REQUEST_METHOD'    => 'GET',
            'SCRIPT_NAME'       => $statusPath,
            'SCRIPT_FILENAME'   => $statusPath,
            'REQUEST_URI'       => $statusPath . ($query ? "?{
            query}" : ''),
            'QUERY_STRING'      => $query,
            'DOCUMENT_URI'      => $statusPath,
            'SERVER_SOFTWARE'   => 'php-fpm-status-parser',
            'SERVER_PROTOCOL'   => 'HTTP/1.1',
            'SERVER_NAME'       => 'localhost',
            'SERVER_PORT'       => '80',
            'CONTENT_TYPE'      => '',
            'CONTENT_LENGTH'    => '0',
        ];
        
        // Send BEGIN_REQUEST (type 1)
        // Format: role (2 bytes) + flags (1 byte) + reserved (5 bytes)
        $beginRequestBody = pack('nCCCCCC', self::FCGI_RESPONDER, 0, 0, 0, 0, 0, 0);
        $this->sendPacket(self::FCGI_BEGIN_REQUEST, $beginRequestBody);
        
        // Send PARAMS
        $paramsData = '';
        foreach ($params as $name => $value) {
            $paramsData .= $this->encodeNameValue($name, $value);
        }
        $this->sendPacket(self::FCGI_PARAMS, $paramsData);
        $this->sendPacket(self::FCGI_PARAMS, ''); // Empty PARAMS to end
        
        // Send empty STDIN
        $this->sendPacket(self::FCGI_STDIN, '');
        
        // Read response
        return $this->readResponse();
    }
    
    private function sendPacket(int $type, string $content): void
    {
        $contentLength = strlen($content);
        $paddingLength = (8 - ($contentLength % 8)) % 8;
        
        // FastCGI header: version, type, requestIdHi, requestIdLo, contentLengthHi, contentLengthLo, paddingLength, reserved
        $header = chr(self::FCGI_VERSION_1)
                . chr($type)
                . chr(($this->requestId >> 8) & 0xFF)
                . chr($this->requestId & 0xFF)
                . chr(($contentLength >> 8) & 0xFF)
                . chr($contentLength & 0xFF)
                . chr($paddingLength)
                . chr(0);
        
        fwrite($this->socket, $header . $content . str_repeat("\0", $paddingLength));
    }
    
    private function readResponse(): string
    {
        $response = '';
        $stderr = '';
        
        while (true) {
            $header = fread($this->socket, 8);
            if (strlen($header) < 8) {
                break;
            }
            
            $version = ord($header[0]);
            $type = ord($header[1]);
            $requestId = (ord($header[2]) << 8) | ord($header[3]);
            $contentLength = (ord($header[4]) << 8) | ord($header[5]);
            $paddingLength = ord($header[6]);
            
            $content = '';
            if ($contentLength > 0) {
                $content = fread($this->socket, $contentLength);
            }
            if ($paddingLength > 0) {
                fread($this->socket, $paddingLength);
            }
            
            switch ($type) {
                case self::FCGI_STDOUT:
                    $response .= $content;
                    break;
                case self::FCGI_STDERR:
                    $stderr .= $content;
                    break;
                case self::FCGI_END_REQUEST:
                    break 2;
            }
        }
        
        if ($stderr) {
            throw new RuntimeException("FastCGI error: {$stderr}");
        }
        
        // Strip HTTP headers from response
        $parts = preg_split('/\r?\n\r?\n/', $response, 2);
        return $parts[1] ?? $response;
    }
    
    private function encodeNameValue(string $name, string $value): string
    {
        $nameLen = strlen($name);
        $valueLen = strlen($value);
        
        $result = '';
        $result .= ($nameLen < 128) ? chr($nameLen) : pack('N', $nameLen | 0x80000000);
        $result .= ($valueLen < 128) ? chr($valueLen) : pack('N', $valueLen | 0x80000000);
        $result .= $name . $value;
        
        return $result;
    }
}

class PhpFpmStatusParser
{
    private array $options;
    private array $poolInfo = [];
    private array $processes = [];
    
    private const SCOREBOARD_STATES = [
        '_' => 'Idle',
        '.' => 'Idle',
        'I' => 'Idle',
        'A' => 'Active',
        'R' => 'Running',
        'D' => 'Done',
        'K' => 'Killing',
        'W' => 'Waiting',
        'C' => 'Closing',
        'S' => 'Starting',
    ];
    
    private const COLORS = [
        'reset'   => "\033[0m",
        'bold'    => "\033[1m",
        'dim'     => "\033[2m",
        'red'     => "\033[31m",
        'green'   => "\033[32m",
        'yellow'  => "\033[33m",
        'blue'    => "\033[34m",
        'magenta' => "\033[35m",
        'cyan'    => "\033[36m",
        'white'   => "\033[37m",
        'bg_blue' => "\033[44m",
    ];

    public function __construct(array $options)
    {
        $this->options = $options;
    }

    public static function parseArgs(array $argv): array
    {
        $options = [
            'target'      => null,
            'status_path' => '/status',
            'watch'       => 0,
            'json'        => false,
            'state'       => null,
            'sort'        => null,
            'quiet'       => false,
            'help'        => false,
            'no_color'    => false,
        ];
        
        $positional = [];
        $i = 1;
        
        while ($i < count($argv)) {
            $arg = $argv[$i];
            
            if ($arg === '-h' || $arg === '--help') {
                $options['help'] = true;
            } elseif ($arg === '-w' || $arg === '--watch') {
                $options['watch'] = (int)($argv[++$i] ?? 2);
            } elseif (str_starts_with($arg, '--watch=')) {
                $options['watch'] = (int)substr($arg, 8);
            } elseif ($arg === '--json') {
                $options['json'] = true;
            } elseif ($arg === '-q' || $arg === '--quiet') {
                $options['quiet'] = true;
            } elseif ($arg === '--no-color') {
                $options['no_color'] = true;
            } elseif ($arg === '--status-path') {
                $options['status_path'] = $argv[++$i] ?? '/status';
            } elseif (str_starts_with($arg, '--status-path=')) {
                $options['status_path'] = substr($arg, 14);
            } elseif ($arg === '--state') {
                $options['state'] = strtolower($argv[++$i] ?? '');
            } elseif (str_starts_with($arg, '--state=')) {
                $options['state'] = strtolower(substr($arg, 8));
            } elseif ($arg === '--sort') {
                $options['sort'] = strtolower($argv[++$i] ?? '');
            } elseif (str_starts_with($arg, '--sort=')) {
                $options['sort'] = strtolower(substr($arg, 7));
            } elseif (!str_starts_with($arg, '-')) {
                $positional[] = $arg;
            }
            
            $i++;
        }
        
        if (!empty($positional)) {
            $options['target'] = $positional[0];
        }
        
        return $options;
    }

    public static function showHelp(): void
    {
        $help = <<<'HELP'

  ┌─────────────────────────────────────────────────────────────────┐
  │             PHP-FPM Status Parser v1.0                          │
  │         Monitor your PHP-FPM pools with style                   │
  └─────────────────────────────────────────────────────────────────┘

  USAGE:
      php fpm-status.php [options] <target>

  TARGET (connection method):
      /path/to/socket       Unix socket (direct FastCGI)
      host:port             TCP socket (direct FastCGI)
      http://host/status    HTTP URL (requires web server proxy)

  OPTIONS:
      -h, --help            Show this help message
      -w, --watch=N         Auto-refresh every N seconds (default: 2)
      -q, --quiet           Show only summary statistics
      --json                Output in JSON format
      --state=STATE         Filter processes by state (idle, running, active)
      --sort=FIELD          Sort by: pid, state, duration, requests, script
      --status-path=PATH    FPM status path (default: /status)
      --no-color            Disable colored output

  EXAMPLES:
      # Connect via Unix socket (most common)
      php fpm-status.php /var/run/php/php-fpm.sock

      # Connect via TCP socket
      php fpm-status.php 127.0.0.1:9000

      # Connect via HTTP (requires nginx/Apache)
      php fpm-status.php http://localhost/status

      # Watch mode - refresh every 5 seconds
      php fpm-status.php -w 5 /var/run/php/php-fpm.sock

      # Show only running processes, sorted by duration
      php fpm-status.php --state=running --sort=duration /var/run/php/php-fpm.sock

      # JSON output for scripting
      php fpm-status.php --json /var/run/php/php-fpm.sock

      # Quiet mode - just the stats
      php fpm-status.php -q /var/run/php/php-fpm.sock

      # Custom status path (must match pm.status_path in pool config)
      php fpm-status.php --status-path=/fpm-status 127.0.0.1:9000

  SETUP:
      Enable status page in your PHP-FPM pool config (e.g., www.conf):
      
          pm.status_path = /status

      Then restart PHP-FPM:
      
          sudo systemctl restart php-fpm

HELP;
        echo $help;
    }

    public function fetch(): string
    {
        $target = $this->options['target'];
        
        if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
            return $this->fetchHttp($target);
        }
        
        return $this->fetchFastCGI($target);
    }
    
    private function fetchHttp(string $url): string
    {
        // Ensure we have ?full parameter
        $separator = str_contains($url, '?') ? '&' : '?';
        if (!str_contains($url, 'full')) {
            $url .= $separator . 'full';
        }
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
            ]
        ]);
        
        $content = @file_get_contents($url, false, $context);
        
        if ($content === false) {
            throw new RuntimeException("Failed to fetch status page from: {$url}");
        }
        
        return $content;
    }
    
    private function fetchFastCGI(string $target): string
    {
        $client = new FastCGIClient($target);
        return $client->request($this->options['status_path'], true);
    }

    public function parse(string $content): void
    {
        $this->poolInfo = [];
        $this->processes = [];
        
        // Check if it's JSON format
        $json = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $this->parseJson($json);
            return;
        }
        
        $this->parsePlainText($content);
    }

    private function parseJson(array $data): void
    {
        $poolFields = [
            'pool', 'process manager', 'start time', 'start since',
            'accepted conn', 'listen queue', 'max listen queue',
            'listen queue len', 'idle processes', 'active processes',
            'total processes', 'max active processes', 'max children reached',
            'slow requests', 'scoreboard'
        ];
        
        foreach ($poolFields as $field) {
            $key = str_replace(' ', '-', $field);
            if (isset($data[$key])) {
                $this->poolInfo[$field] = $data[$key];
            } elseif (isset($data[$field])) {
                $this->poolInfo[$field] = $data[$field];
            }
        }
        
        if (isset($data['processes']) && is_array($data['processes'])) {
            $this->processes = $data['processes'];
        }
    }

    private function parsePlainText(string $content): void
    {
        $lines = explode("\n", $content);
        $currentProcess = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                if ($currentProcess !== null) {
                    $this->processes[] = $currentProcess;
                    $currentProcess = null;
                }
                continue;
            }
            
            if (strpos($line, '***') !== false) {
                $currentProcess = [];
                continue;
            }
            
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                
                if ($currentProcess !== null) {
                    $currentProcess[$key] = $value;
                } else {
                    $this->poolInfo[$key] = $value;
                }
            }
        }
        
        if ($currentProcess !== null && !empty($currentProcess)) {
            $this->processes[] = $currentProcess;
        }
    }

    private function filterAndSortProcesses(): array
    {
        $processes = $this->processes;
        
        // Filter by state
        if ($this->options['state']) {
            $stateFilter = $this->options['state'];
            $processes = array_filter($processes, function($proc) use ($stateFilter) {
                $state = strtolower($proc['state'] ?? '');
                return str_contains($state, $stateFilter);
            });
        }
        
        // Sort
        if ($this->options['sort']) {
            $sortField = $this->options['sort'];
            usort($processes, function($a, $b) use ($sortField) {
                return match($sortField) {
                    'pid' => ($a['pid'] ?? 0) <=> ($b['pid'] ?? 0),
                    'state' => ($a['state'] ?? '') <=> ($b['state'] ?? ''),
                    'duration' => ($b['request duration'] ?? 0) <=> ($a['request duration'] ?? 0),
                    'requests' => ($b['requests'] ?? 0) <=> ($a['requests'] ?? 0),
                    'script' => ($a['script'] ?? '') <=> ($b['script'] ?? ''),
                    default => 0,
                };
            });
        }
        
        return $processes;
    }

    public function outputJson(): void
    {
        $output = [
            'pool' => $this->poolInfo,
            'processes' => $this->filterAndSortProcesses(),
            'summary' => $this->getSummary(),
        ];
        
        echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
    }
    
    private function getSummary(): array
    {
        $total = (int)($this->poolInfo['total processes'] ?? 0);
        $active = (int)($this->poolInfo['active processes'] ?? 0);
        $idle = (int)($this->poolInfo['idle processes'] ?? 0);
        
        $summary = [
            'total_processes' => $total,
            'active_processes' => $active,
            'idle_processes' => $idle,
            'utilization_percent' => $total > 0 ? round(($active / $total) * 100, 1) : 0,
        ];
        
        if (!empty($this->processes)) {
            $summary['total_requests_served'] = array_sum(array_column($this->processes, 'requests'));
            $summary['avg_requests_per_process'] = round($summary['total_requests_served'] / count($this->processes), 1);
        }
        
        return $summary;
    }

    public function displayPoolInfo(): void
    {
        $c = fn($color) => $this->c($color);
        
        echo "\n" . $c('bold') . $c('bg_blue') . $c('white') . " PHP-FPM Pool Status " . $c('reset') . "\n\n";
        
        $table = [
            ['Pool Name', $this->poolInfo['pool'] ?? 'N/A'],
            ['Process Manager', $this->poolInfo['process manager'] ?? 'N/A'],
            ['Start Time', $this->poolInfo['start time'] ?? 'N/A'],
            ['Uptime', $this->formatUptime($this->poolInfo['start since'] ?? 0)],
            ['Accepted Connections', $this->poolInfo['accepted conn'] ?? 'N/A'],
            ['Listen Queue', $this->poolInfo['listen queue'] ?? 'N/A'],
            ['Max Listen Queue', $this->poolInfo['max listen queue'] ?? 'N/A'],
            ['Listen Queue Length', $this->poolInfo['listen queue len'] ?? 'N/A'],
            ['Idle Processes', $c('green') . ($this->poolInfo['idle processes'] ?? 'N/A') . $c('reset')],
            ['Active Processes', $c('yellow') . ($this->poolInfo['active processes'] ?? 'N/A') . $c('reset')],
            ['Total Processes', $this->poolInfo['total processes'] ?? 'N/A'],
            ['Max Active Processes', $this->poolInfo['max active processes'] ?? 'N/A'],
            ['Max Children Reached', $this->colorizeMaxChildren($this->poolInfo['max children reached'] ?? 0)],
            ['Slow Requests', $this->colorizeSlow($this->poolInfo['slow requests'] ?? 0)],
        ];
        
        $maxKeyLen = max(array_map(fn($row) => strlen($row[0]), $table));
        
        foreach ($table as $row) {
            $key = str_pad($row[0], $maxKeyLen);
            echo "  " . $c('cyan') . $key . $c('reset') . " : " . $row[1] . "\n";
        }
    }

    public function displayScoreboard(): void
    {
        $c = fn($color) => $this->c($color);
        $scoreboard = $this->poolInfo['scoreboard'] ?? '';
        
        if (empty($scoreboard)) {
            return;
        }
        
        echo "\n" . $c('bold') . $c('bg_blue') . $c('white') . " Scoreboard " . $c('reset') . "\n\n";
        
        // Display scoreboard with colors
        echo "  ";
        $charsPerLine = 60;
        $chars = str_split($scoreboard);
        foreach ($chars as $i => $char) {
            echo $this->colorizeScoreboardChar($char);
            if (($i + 1) % $charsPerLine === 0 && $i < count($chars) - 1) {
                echo $c('reset') . "\n  ";
            }
        }
        echo $c('reset') . "\n\n";
        
        // Legend with counts
        $counts = array_count_values(str_split($scoreboard));
        $legend = [
            ['_/.', 'Idle', 'green', ($counts['_'] ?? 0) + ($counts['.'] ?? 0) + ($counts['I'] ?? 0)],
            ['A', 'Active', 'yellow', $counts['A'] ?? 0],
            ['R', 'Running', 'magenta', $counts['R'] ?? 0],
            ['D', 'Done', 'blue', $counts['D'] ?? 0],
            ['K', 'Killing', 'red', $counts['K'] ?? 0],
        ];
        
        echo "  ";
        foreach ($legend as $item) {
            if ($item[3] > 0) {
                echo $c($item[2]) . "■" . $c('reset') . " " . $item[0] . "=" . $item[3] . "  ";
            }
        }
        echo "\n";
    }

    public function displayProcessTable(): void
    {
        $c = fn($color) => $this->c($color);
        $processes = $this->filterAndSortProcesses();
        
        if (empty($processes)) {
            echo "\n" . $c('yellow') . "No processes match the filter criteria." . $c('reset') . "\n";
            echo $c('dim') . "Tip: Use status URL with '?full' for detailed process info." . $c('reset') . "\n";
            return;
        }
        
        echo "\n" . $c('bold') . $c('bg_blue') . $c('white') . " Process Details " . $c('reset');
        if ($this->options['state']) {
            echo " " . $c('dim') . "(filtered: " . $this->options['state'] . ")" . $c('reset');
        }
        if ($this->options['sort']) {
            echo " " . $c('dim') . "(sorted: " . $this->options['sort'] . ")" . $c('reset');
        }
        echo "\n\n";
        
        $headers = ['PID', 'State', 'Start', 'Duration', 'Reqs', 'Method', 'URI'];
        
        $rows = [];
        foreach ($processes as $proc) {
            $rows[] = [
                $proc['pid'] ?? 'N/A',
                $proc['state'] ?? 'N/A',
                $this->formatProcessStartTime($proc['start time'] ?? $proc['request start timestamp'] ?? 0),
                $this->formatDuration($proc['request duration'] ?? 0),
                $proc['requests'] ?? 'N/A',
                $proc['request method'] ?? '-',
                $proc['request uri'] ?? '-',
            ];
        }
        
        // Calculate column widths
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], strlen((string)$cell));
            }
        }
        
        // Header
        echo "  " . $c('bold') . $c('cyan');
        foreach ($headers as $i => $header) {
            echo str_pad($header, $widths[$i] + 2);
        }
        echo $c('reset') . "\n  " . $c('dim');
        foreach ($widths as $w) {
            echo str_repeat('─', $w) . '  ';
        }
        echo $c('reset') . "\n";
        
        // Rows
        foreach ($rows as $row) {
            echo '  ';
            foreach ($row as $i => $cell) {
                $color = ($i === 1) ? $this->getStateColor((string)$cell) : '';
                echo $color . str_pad((string)$cell, $widths[$i] + 2) . ($color ? $c('reset') : '');
            }
            echo "\n";
        }
        
        echo "\n  " . $c('bold') . "Showing:" . $c('reset') . " " . count($rows) . " process(es)\n";
    }

    public function displaySummary(): void
    {
        $c = fn($color) => $this->c($color);
        
        echo "\n" . $c('bold') . $c('bg_blue') . $c('white') . " Summary " . $c('reset') . "\n\n";
        
        $total = (int)($this->poolInfo['total processes'] ?? 0);
        $active = (int)($this->poolInfo['active processes'] ?? 0);
        $idle = (int)($this->poolInfo['idle processes'] ?? 0);
        
        if ($total > 0) {
            $activePercent = round(($active / $total) * 100, 1);
            $idlePercent = round(($idle / $total) * 100, 1);
            
            $barWidth = 50;
            $activeBar = (int)(($active / $total) * $barWidth);
            $idleBar = $barWidth - $activeBar;
            
            echo "  Utilization: [" . $c('yellow') . str_repeat('█', $activeBar) . 
                 $c('green') . str_repeat('█', $idleBar) . $c('reset') . "]\n";
            echo "               " . $c('yellow') . "Active: " . $activePercent . "%" . $c('reset') . " │ " .
                 $c('green') . "Idle: " . $idlePercent . "%" . $c('reset') . "\n";
        }
        
        if (!empty($this->processes)) {
            $totalRequests = array_sum(array_column($this->processes, 'requests'));
            $avgRequests = count($this->processes) > 0 ? round($totalRequests / count($this->processes), 1) : 0;
            
            // Find longest running request
            $maxDuration = 0;
            $maxDurationScript = '';
            foreach ($this->processes as $proc) {
                $duration = (int)($proc['request duration'] ?? 0);
                if ($duration > $maxDuration) {
                    $maxDuration = $duration;
                    $maxDurationScript = basename($proc['script'] ?? 'unknown');
                }
            }
            
            // Count scripts
            $scripts = [];
            foreach ($this->processes as $proc) {
                $script = $proc['script'] ?? '';
                if ($script && $script !== '-') {
                    $scripts[basename($script)] = ($scripts[basename($script)] ?? 0) + 1;
                }
            }
            
            echo "\n  " . $c('cyan') . "Total Requests Served:" . $c('reset') . "  " . $totalRequests . "\n";
            echo "  " . $c('cyan') . "Avg Requests/Process:" . $c('reset') . "   " . $avgRequests . "\n";
            
            if ($maxDuration > 0) {
                echo "  " . $c('cyan') . "Longest Running:" . $c('reset') . "        " . $this->formatDuration($maxDuration) . " (" . $maxDurationScript . ")\n";
            }
            
            if (!empty($scripts)) {
                arsort($scripts);
                echo "\n  " . $c('bold') . "Top Scripts:" . $c('reset') . "\n";
                $i = 0;
                foreach ($scripts as $script => $count) {
                    if ($i++ >= 5) break;
                    $bar = str_repeat('▪', min($count, 20));
                    echo "    " . $c('dim') . $bar . $c('reset') . " " . $count . "× " . $script . "\n";
                }
            }
        }
    }

    public function run(): void
    {
        if ($this->options['help'] || !$this->options['target']) {
            self::showHelp();
            exit($this->options['help'] ? 0 : 1);
        }
        
        $watch = $this->options['watch'];
        
        do {
            try {
                // Fetch and parse data BEFORE clearing screen
                $content = $this->fetch();
                $this->parse($content);
                
                // Buffer the output
                ob_start();
                
                if ($this->options['json']) {
                    $this->outputJson();
                } elseif ($this->options['quiet']) {
                    $this->displaySummary();
                } else {
                    $this->displayPoolInfo();
                    $this->displayScoreboard();
                    $this->displayProcessTable();
                    $this->displaySummary();
                }
                
                if ($watch > 0) {
                    $c = fn($color) => $this->c($color);
                    echo "\n" . $c('dim') . "Refreshing every " . $watch . "s. Press Ctrl+C to exit." . $c('reset') . "\n";
                }
                
                $output = ob_get_clean();
                
                // NOW clear screen and print buffered output (instant, no flicker)
                if ($watch > 0) {
                    echo "\033[2J\033[H"; // Clear screen and move cursor to top
                }
                
                echo $output;
                
                if ($watch > 0) {
                    sleep($watch);
                }
                
            } catch (Exception $e) {
                $c = fn($color) => $this->c($color);
                if ($watch > 0) {
                    echo "\033[2J\033[H";
                }
                echo $c('red') . "Error: " . $e->getMessage() . $c('reset') . "\n";
                if ($watch === 0) {
                    exit(1);
                }
                sleep($watch);
            }
        } while ($watch > 0);
        
        echo "\n";
    }

    // Helper methods
    
    private function formatUptime(int|string $seconds): string
    {
        $seconds = (int)$seconds;
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        $parts = [];
        if ($days > 0) $parts[] = $days . "d";
        if ($hours > 0) $parts[] = $hours . "h";
        if ($minutes > 0) $parts[] = $minutes . "m";
        $parts[] = $secs . "s";
        
        return implode(' ', $parts);
    }

    private function formatDuration(int|string $microseconds): string
    {
        $microseconds = (int)$microseconds;
        if ($microseconds === 0) return '-';
        
        if ($microseconds < 1000) {
            return $microseconds . "μs";
        } elseif ($microseconds < 1000000) {
            return round($microseconds / 1000, 2) . "ms";
        } else {
            return round($microseconds / 1000000, 2) . "s";
        }
    }

    private function formatProcessStartTime(int|string $timestamp): string
    {
        if (empty($timestamp) || $timestamp === '0') return '-';
        if (is_numeric($timestamp) && $timestamp > 1000000000000) {
            $timestamp = (int)($timestamp / 1000000);
        }
        return date('H:i:s', (int)$timestamp);
    }

    private function truncate(string $str, int $maxLen): string
    {
        if (strlen($str) <= $maxLen) return $str;
        return substr($str, 0, $maxLen - 3) . '...';
    }

    private function colorizeMaxChildren(int|string $value): string
    {
        $c = fn($color) => $this->c($color);
        $value = (int)$value;
        return $value > 0 ? $c('red') . $value . " ⚠" . $c('reset') : $c('green') . $value . $c('reset');
    }

    private function colorizeSlow(int|string $value): string
    {
        $c = fn($color) => $this->c($color);
        $value = (int)$value;
        return $value > 0 ? $c('yellow') . $value . $c('reset') : $c('green') . $value . $c('reset');
    }

    private function colorizeScoreboardChar(string $char): string
    {
        $c = fn($color) => $this->c($color);
        return match ($char) {
            '_', '.', 'I' => $c('green') . $char,
            'A' => $c('yellow') . $char,
            'R' => $c('magenta') . $char,
            'D' => $c('blue') . $char,
            'K', 'C' => $c('red') . $char,
            default => $char,
        };
    }

    private function getStateColor(string $state): string
    {
        $c = fn($color) => $this->c($color);
        $state = strtolower($state);
        if (str_contains($state, 'idle') || str_contains($state, 'waiting')) {
            return $c('green');
        }
        if (str_contains($state, 'running') || str_contains($state, 'busy')) {
            return $c('yellow');
        }
        if (str_contains($state, 'finishing')) {
            return $c('blue');
        }
        return '';
    }
}

// Main
$options = PhpFpmStatusParser::parseArgs($argv);
$parser = new PhpFpmStatusParser($options);
$parser->run();