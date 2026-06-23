<?php
declare(strict_types=1);
ini_set('memory_limit', '512M');
set_time_limit(0);
$rootDir = dirname(__DIR__);
require_once $rootDir . '/vendor/autoload.php';
Config::init($rootDir . '/config/app.config.php');
$_runtimeSettings = $rootDir . '/config/runtime_settings.json';
if (is_readable($_runtimeSettings)) {
    $overrides = json_decode(file_get_contents($_runtimeSettings), true) ?? [];
    Config::merge($overrides);
}
unset($_runtimeSettings);

function cli_parse_args(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z0-9_-]+)(?:=(.+))?$/i', $arg, $m)) {
            $args[$m[1]] = $m[2] ?? true;
        }
    }
    return $args;
}

function cli_log(string $level, int $jobId, string $msg): void
{
    $line = sprintf("[%s] [%s] [job_id=%d] %s\n", date('Y-m-d H:i:s'), $level, $jobId, $msg);
    file_put_contents(Config::get('logs_dir') . '/backup-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

function cli_exit(string $msg, int $code = 1): never
{
    fwrite(STDERR, $msg . "\n");
    exit($code);
}
