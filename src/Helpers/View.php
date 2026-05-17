<?php
declare(strict_types=1);

namespace App\Helpers;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Wrapper Twig con globals pre-impostati e helper applicativi.
 *
 * Globals esposti a tutte le viste:
 * - app: nome, debug, url, env
 * - organization: nome ente
 * - currentUser: utente loggato (o null)
 * - flash: messaggi flash della richiesta corrente
 *
 * Funzioni esposte:
 * - csrf_field()    -> input nascosto con il token
 * - csrf_token()    -> stringa token
 * - asset(path)     -> URL versionato dell'asset
 * - url(path)       -> URL assoluto
 * - format_date(d)  -> data formattata it_IT
 */
final class View
{
    private Environment $twig;

    public function __construct(
        private readonly Session $session,
        private readonly Csrf $csrf,
    ) {
        $debug = (bool) Config::get('app.debug', false);

        $loader = new FilesystemLoader(APP_ROOT . '/views');
        $this->twig = new Environment($loader, [
            'cache'            => $debug ? false : APP_ROOT . '/storage/cache/twig',
            'auto_reload'      => $debug,
            'debug'            => $debug,
            'strict_variables' => false,
            'charset'          => 'utf-8',
        ]);

        $this->registerGlobals();
        $this->registerFunctions();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        // Globals di richiesta che cambiano per ogni risposta
        $this->twig->addGlobal('flash', $this->session->consumeFlash());
        $this->twig->addGlobal('currentUser', $this->session->get('user'));
        $this->twig->addGlobal('old_input', $this->session->consumeOldInput());
        $this->twig->addGlobal('form_errors', $this->session->consumeFormErrors());
        return $this->twig->render($template, $data);
    }

    private function registerGlobals(): void
    {
        $this->twig->addGlobal('app', [
            'name'  => Config::get('app.name', 'Planner Hospice'),
            'debug' => (bool) Config::get('app.debug', false),
            'env'   => Config::get('app.env', 'production'),
            'url'   => Config::get('app.url', ''),
            'year'  => (int) date('Y'),
        ]);
        $this->twig->addGlobal('organization', [
            'name' => Config::get('organization.name', 'Hospice'),
        ]);
    }

    private function registerFunctions(): void
    {
        $this->twig->addFunction(new TwigFunction(
            'csrf_token',
            fn (): string => $this->csrf->token()
        ));
        $this->twig->addFunction(new TwigFunction(
            'csrf_field',
            fn (): string => sprintf(
                '<input type="hidden" name="%s" value="%s">',
                Csrf::FIELD,
                htmlspecialchars($this->csrf->token(), ENT_QUOTES, 'UTF-8')
            ),
            ['is_safe' => ['html']]
        ));
        $this->twig->addFunction(new TwigFunction(
            'asset',
            fn (string $path): string => Url::asset($path)
        ));
        $this->twig->addFunction(new TwigFunction(
            'url',
            fn (string $path): string => Url::to($path)
        ));
        $this->twig->addFunction(new TwigFunction(
            'format_date',
            function (string|\DateTimeInterface|null $value, string $format = 'd/m/Y'): string {
                if ($value === null) return '';
                $dt = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value);
                return $dt->format($format);
            }
        ));
    }
}
