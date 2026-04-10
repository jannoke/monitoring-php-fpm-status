$headers = ['PID', 'State', 'Start', 'Duration', 'Reqs', 'Method', 'Script', 'URI'];

$rows[] = [
    $proc['pid'] ?? 'N/A',
    $proc['state'] ?? 'N/A',
    $this->formatProcessStartTime($proc['start time'] ?? $proc['request start timestamp'] ?? 0),
    $this->formatDuration($proc['request duration'] ?? 0),
    $proc['requests'] ?? 'N/A',
    $proc['request method'] ?? '-',
    $this->truncate(basename($proc['script'] ?? '-'), 20),
    $proc['request URI'] ?? $proc['request uri'] ?? '-',
];