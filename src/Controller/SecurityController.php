<?php

namespace App\Controller;

use App\Form\ForgottenPasswordType;
use App\Form\ResetPasswordType;
use App\Repository\UserRepository;
use DateInterval;
use DateTimeImmutable;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Uid\Uuid;

class SecurityController extends AbstractController
{
    /**
     * @Route("/connexion", name="security_login" , methods={"GET", "POST"})
     * @param AuthenticationUtils $authenticationUtils
     * @return Response
     */
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // if ($this->getUser()) {
        //     return $this->redirectToRoute('target_path');
        // }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', ['last_username' => $lastUsername, 'error' => $error]);
    }

    /**
     * @Route("/deconnexion", name="security_logout")
     */
    public function logout()
    {
    }

    /**
     * @Route("/mot-de-passe-oublie", name="security_forgotten_password", methods={"GET","POST"})
     * @param Request $request
     * @param UserRepository $userRepository
     * @param MailerInterface $mailer
     * @return Response
     */
    public function forgottenPassword(
        Request $request,
        UserRepository $userRepository,
        MailerInterface $mailer
    ): Response {

        $form = $this->createForm(ForgottenPasswordType::class)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $user = $userRepository->findOneBy(["email" => $email]);
            $user->hasForgotHisPassword();
            $this->getDoctrine()->getManager()->flush();

            $expiredDate = ($user->getForgottenPassword()->getRequestedAt())
                ->add(new DateInterval("PT15M"));

            $email = (new TemplatedEmail())
                ->from("admin@fake.com")
                ->to($user->getEmail())
                ->subject("Réinitialisation mot de passe")
                ->htmlTemplate("emails/forgotten_password.html.twig")
                ->context([
                    "linkToResetPassword" => $this->generateUrl(
                        "security_reset_password",
                        ["token" => $user->getForgottenPassword()->getToken()],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                    "time" => sprintf(
                        "%sh%s",
                        $expiredDate->format("H"),
                        $expiredDate->format("i")
                    )
                ]);

            try {
                $mailer->send($email);
            } catch (TransportExceptionInterface $e) {
                $this->addFlash(
                    "danger",
                    "Une erreur est survenue. <br> Merci de bien vouloir effectuer une nouvelle demande."
                );
                return $this->redirectToRoute("security_forgotten_password");
            }
            $this->addFlash(
                "success",
                "Un email vient de vous être envoyé 
                    avec les instructions nécessaires afin de réunitialiser votre mot de passe."
            );
            return $this->redirectToRoute("security_login");
        }

        return $this->render('security/forgotten_password.html.twig', [
            "form" => $form->createView()
        ]);
    }

    /**
     * @Route("/reinitialisation-du-mot-de-passe/{token}", name="security_reset_password", methods={"GET","POST"})
     * @param string $token
     * @param UserRepository $userRepository
     * @param Request $request
     * @param UserPasswordEncoderInterface $encoder
     * @return Response
     */
    public function resetPassword(
        string $token,
        UserRepository $userRepository,
        Request $request,
        UserPasswordEncoderInterface $encoder
    ): Response {

        if ($this->getUser()) {
            return $this->redirectToRoute("home");
        }

        if (
            Uuid::isValid($token) === false
            || null === $user = $userRepository->findOneBy(["forgottenPassword.token" => $token])
        ) {
            $this->addFlash(
                "danger",
                "Le lien envoyé par mail n'est plus valide. <br> Merci de bien vouloir effectuer une nouvelle demande."
            );
            return $this->redirectToRoute("security_forgotten_password");
        }

        if (
            new DateTimeImmutable() >
            ($user->getForgottenPassword()->getRequestedAt())->add(new DateInterval("PT15M"))
        ) {
            $this->addFlash(
                "info",
                "Le lien envoyé par mail a expiré.<br>Merci de bien vouloir effectuer une nouvelle demande."
            );
            return $this->redirectToRoute("security_forgotten_password");
        }


        $form = $this->createForm(ResetPasswordType::class)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $password = $form->get("plainPassword")->getData();
            $user->setPassword($encoder->encodePassword($user, $password));
            $user->setForgottenPassword(null);
            $this->getDoctrine()->getManager()->flush();
            $this->addFlash("success", "Votre mot de passe a bien été réinitialisé.");
            return $this->redirectToRoute("security_login");
        }

        return $this->render('security/reset_password.html.twig', [
            "form" => $form->createView()
        ]);
    }
}
