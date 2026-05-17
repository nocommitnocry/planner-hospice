<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Routing\Request;
use App\Routing\Response;
use App\Services\AuthService;

final class AuthController extends BaseController
{
    public function showLogin(Request $request): Response
    {
        // Se già loggato, manda alla home
        if ($this->currentUser() !== null) {
            return $this->redirect('/');
        }
        return $this->render('auth/login.twig');
    }

    public function doLogin(Request $request): Response
    {
        $username = (string) $request->post('username', '');
        $password = (string) $request->post('password', '');

        if ($username === '' || $password === '') {
            $this->session->flash('error', 'Inserire username e password.');
            return $this->redirect('/login');
        }

        $service = new AuthService();
        $result = $service->login($username, $password, $request->ip());

        if (!$result['ok']) {
            $this->session->flash('error', $result['message']);
            return $this->redirect('/login');
        }

        $this->session->flash('success', 'Accesso effettuato.');
        return $this->redirect('/');
    }

    public function logout(Request $request): Response
    {
        (new AuthService())->logout();
        // session_destroy() ha pulito anche il flash: lo rimettiamo dopo
        $this->session->start();
        $this->session->flash('success', 'Disconnessione effettuata.');
        return $this->redirect('/login');
    }

    public function showChangePassword(Request $request): Response
    {
        return $this->render('auth/change_password.twig');
    }

    public function doChangePassword(Request $request): Response
    {
        $user = $this->currentUser();
        if ($user === null) {
            return $this->redirect('/login');
        }

        $current = (string) $request->post('current_password', '');
        $new     = (string) $request->post('new_password', '');
        $confirm = (string) $request->post('confirm_password', '');

        $service = new AuthService();
        $result = $service->changePassword((int) $user['id'], $current, $new, $confirm);

        if (!$result['ok']) {
            $this->session->flash('error', $result['message']);
            return $this->redirect('/change-password');
        }

        $this->session->flash('success', $result['message']);
        return $this->redirect('/');
    }
}
