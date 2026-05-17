<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Container;
use App\Helpers\Session;
use App\Helpers\View;
use App\Routing\Response;

/**
 * Controller base.
 *
 * Niente più logica di auth/authz sparpagliata: quella la fa la pipeline
 * di middleware. Qui ci sono solo helper di rendering e shortcut JSON.
 */
abstract class BaseController
{
    protected View $view;
    protected Session $session;

    public function __construct()
    {
        $container = Container::instance();
        $this->view = $container->get(View::class);
        $this->session = $container->get(Session::class);
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return new Response($this->view->render($template, $data), $status);
    }

    protected function redirect(string $url, ?string $flashType = null, ?string $flashMessage = null): Response
    {
        if ($flashType !== null && $flashMessage !== null) {
            $this->session->flash($flashType, $flashMessage);
        }
        return Response::redirect($url);
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /** @return array<string,mixed>|null */
    protected function currentUser(): ?array
    {
        $user = $this->session->get('user');
        return is_array($user) ? $user : null;
    }

    protected function currentUserId(): ?int
    {
        $u = $this->currentUser();
        return $u !== null ? (int) $u['id'] : null;
    }

    /**
     * Redirect con flash di errori di validazione e old input.
     * Il chiamante è responsabile di rimuovere campi sensibili (password) da $oldInput.
     *
     * @param array<string,list<string>> $errors
     * @param array<string,mixed> $oldInput
     */
    protected function redirectWithErrors(
        string $url,
        array $errors,
        array $oldInput,
        string $message = 'Correggi i campi evidenziati.',
    ): Response {
        $this->session->flashErrors($errors);
        $this->session->flashInput($oldInput);
        return $this->redirect($url, 'error', $message);
    }
}
