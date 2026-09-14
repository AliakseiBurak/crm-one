<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    #[Route('/login', name: 'app_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        // Email, введённый в форме установки пароля: сохраняется при
        // редиректе на /login после ошибки валидации (PRG).
        $session = $request->getSession();
        $setupEmail = $session->has('setup_password_email')
            ? (string) $session->get('setup_password_email')
            : '';
        $session->remove('setup_password_email');

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'setup_email' => $setupEmail,
        ]);
    }

    #[Route('/setup-password', name: 'app_setup_password', methods: ['POST'])]
    public function setupPassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        $email = trim((string) $request->request->get('email', ''));
        $newPassword = (string) $request->request->get('new_password', '');
        $confirmPassword = (string) $request->request->get('confirm_password', '');

        $errors = [];

        if ('' === $email) {
            $errors[] = 'Введите email';
        }

        $user = null;
        if ('' !== $email) {
            $user = $this->users->findOneByEmailWithNoPassword($email);
            if (null === $user) {
                $errors[] = 'Пользователь не найден или пароль уже установлен';
            }
        }

        if ('' === $newPassword) {
            $errors[] = 'Введите новый пароль';
        } elseif (mb_strlen($newPassword) < 8) {
            $errors[] = 'Пароль должен содержать не менее 8 символов';
        }

        if ('' === $confirmPassword) {
            $errors[] = 'Подтвердите пароль';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'Пароли не совпадают';
        }

        if ([] !== $errors) {
            if ('' !== $email) {
                $request->getSession()->set('setup_password_email', $email);
            }
            $this->addFlash('error', implode(' ', $errors));

            return $this->redirectToRoute('app_login');
        }

        /** @var \App\Entity\User $user */
        $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
        $em->flush();

        $this->addFlash('success', 'Пароль установлен. Теперь вы можете войти.');

        return $this->redirectToRoute('app_login');
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
