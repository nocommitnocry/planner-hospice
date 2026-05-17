<?php
declare(strict_types=1);

namespace App\Helpers;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;

/**
 * Wrapper Monolog. Rotazione giornaliera in storage/logs/.
 *
 * Uso: Logger::get()->info('messaggio', ['contesto' => 1]);
 */
final class Logger
{
    private static ?MonologLogger $instance = null;

    public function __construct()
    {
        if (self::$instance === null) {
            $this->build();
        }
    }

    public static function get(): LoggerInterface
    {
        if (self::$instance === null) {
            // Fallback: chiamato prima del Container, costruisce al volo
            new self();
        }
        return self::$instance;
    }

    private function build(): MonologLogger
    {
        $level = $this->parseLevel((string) Config::get('app.log.level', 'info'));
        $path = (string) Config::get('app.log.path', APP_ROOT . '/storage/logs/app.log');

        $logger = new MonologLogger('app');
        // 14 file giornalieri di retention
        $logger->pushHandler(new RotatingFileHandler($path, 14, $level));
        self::$instance = $logger;
        return $logger;
    }

    private function parseLevel(string $name): Level
    {
        return match (strtolower($name)) {
            'debug'     => Level::Debug,
            'notice'    => Level::Notice,
            'warning'   => Level::Warning,
            'error'     => Level::Error,
            'critical'  => Level::Critical,
            'alert'     => Level::Alert,
            'emergency' => Level::Emergency,
            default     => Level::Info,
        };
    }
}
