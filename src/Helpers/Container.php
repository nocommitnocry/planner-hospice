<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Routing\Router;
use Closure;
use RuntimeException;

/**
 * Service container minimale.
 *
 * Risolve dipendenze condivise (singleton) e costruisce istanze ad-hoc dei
 * controller. Niente reflection-magic: i binding sono espliciti in boot().
 */
final class Container
{
    private static ?self $instance = null;

    /** @var array<class-string,object> */
    private array $shared = [];

    /** @var array<class-string,Closure> */
    private array $factories = [];

    public static function boot(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $c = new self();

        // Singleton condivisi
        $c->factories[Config::class] = fn () => new Config();
        $c->factories[Logger::class] = fn () => new Logger();
        $c->factories[Database::class] = fn () => new Database();
        $c->factories[Session::class] = fn () => new Session();
        $c->factories[Csrf::class] = fn () => new Csrf($c->get(Session::class));
        $c->factories[View::class] = fn () => new View($c->get(Session::class), $c->get(Csrf::class));
        $c->factories[Router::class] = fn () => new Router($c);

        self::$instance = $c;
        return $c;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Container non ancora avviato. Chiamare Container::boot() prima.');
        }
        return self::$instance;
    }

    /**
     * Ottiene un servizio condiviso. La prima richiesta lo costruisce, le
     * successive restituiscono la stessa istanza.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class): object
    {
        if (isset($this->shared[$class])) {
            /** @var T */
            return $this->shared[$class];
        }
        if (isset($this->factories[$class])) {
            $instance = ($this->factories[$class])();
            $this->shared[$class] = $instance;
            /** @var T */
            return $instance;
        }
        throw new RuntimeException("Servizio non registrato nel container: {$class}");
    }

    /**
     * Costruisce un'istanza nuova (es. un controller per ogni richiesta).
     * Per i controller, ne facciamo un new diretto: tutte le dipendenze
     * vengono ottenute al loro interno via Container::instance().
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function make(string $class): object
    {
        /** @var T */
        return new $class();
    }
}
