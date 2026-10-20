<?php


namespace App\Action\System;


use App\Attribute\JwtAuthenticationDisabledAttribute;
use App\DTO\Settings\Lock\Subsettings\SettingsModuleLockDTO;
use App\Entity\User;
use App\Enum\PendoTrackEventEnum;
use App\Response\Base\BaseResponse;
use App\Services\External\PendoTrackService;
use App\Services\RequestService;
use App\Services\Security\JwtAuthenticationService;
use App\Services\Security\PasswordHashingService;
use App\Services\Settings\SettingsLockModuleService;
use App\Services\Storage\RequestSessionStorage;
use App\Services\System\EnvReader;
use App\Services\System\SecurityService;
use App\Services\TypeProcessor\ArrayHandler;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class AppAction extends AbstractController
{

    public function __construct(
        private readonly JwtAuthenticationService $jwtAuthenticationService,
        private readonly EntityManagerInterface   $em,
        private readonly PasswordHashingService   $passwordHashingService,
        private readonly TranslatorInterface      $translator,
        private readonly PendoTrackService        $pendoTrackService,
    ) {
    }

    /**
     * This method will either:
     * - set system in unlock state where locked resources are accessible,
     * - set system in lock state where locked resources are hidden,
     *
     * @Route("/toggle-resources-lock", name="system-toggle-resources-lock", methods="POST")
     * @param Request                   $request
     * @param SecurityService           $securityService
     * @param SettingsLockModuleService $settingsLockModuleService
     *
     * @return JsonResponse
     * @throws Exception
     */
    public function toggleResourcesLock(Request $request, SecurityService $securityService, SettingsLockModuleService $settingsLockModuleService): Response
    {
        RequestSessionStorage::$IS_TOGGLE_LOCK_CALL = true;

        $dataArray = RequestService::tryFromJsonBody($request);
        $password  = ArrayHandler::get($dataArray, 'password');

        $lockedModulesCount = count(array_filter(
            $settingsLockModuleService->getSettingsModuleLockDtos(),
            fn(SettingsModuleLockDTO $lockDto) => $lockDto->isLocked()
        ));

        $user = $this->jwtAuthenticationService->getUserFromRequest();
        if (!$this->jwtAuthenticationService->isSystemLocked()) {
            RequestSessionStorage::$IS_SYSTEM_LOCKED = true;
            $this->trackSystemLockStateChanged("locked", "success", $lockedModulesCount);

            $message = $this->translator->trans("security.lockResource.wholeSystemWasLocked");
            return BaseResponse::buildOkResponse($message)->toJsonResponse();
        }

        $userPassword    = $user->getLockPassword();
        $isPasswordValid = $securityService->isPasswordValid($user, $userPassword, $password);
        if (!$isPasswordValid) {
            $this->trackSystemLockStateChanged("locked", "invalid_lock_password", $lockedModulesCount);

            $message = $this->translator->trans('security.lockResource.invalidPassword');
            return BaseResponse::buildBadRequestErrorResponse($message)->toJsonResponse();
        }

        RequestSessionStorage::$IS_SYSTEM_LOCKED = false;
        $this->trackSystemLockStateChanged("unlocked", "success", $lockedModulesCount);

        $message = $this->translator->trans("security.lockResource.wholeSystemHasBeenUnlocked");
        return BaseResponse::buildOkResponse($message)->toJsonResponse();
    }


    /**
     * Handles registering user
     *
     * @param Request $request
     *
     * @return JsonResponse
     * @throws Exception
     */
    #[Route("/register-user", name: 'register_user', methods: [Request::METHOD_POST, Request::METHOD_OPTIONS])]
    #[JwtAuthenticationDisabledAttribute]
    public function registerUser(Request $request): JsonResponse
    {
        $dataArray             = RequestService::tryFromJsonBody($request);
        $email                 = ArrayHandler::get($dataArray, 'email');
        $username              = ArrayHandler::get($dataArray, 'username');
        $password              = ArrayHandler::get($dataArray, 'password');
        $passwordConfirmed     = ArrayHandler::get($dataArray, 'passwordConfirmed');
        $lockPassword          = ArrayHandler::get($dataArray, 'lockPassword');
        $lockPasswordConfirmed = ArrayHandler::get($dataArray, 'lockPasswordConfirmed');

        if ($password !== $passwordConfirmed) {
            $this->trackRegistrationRejected("password_mismatch");
            return BaseResponse::buildBadRequestErrorResponse($this->translator->trans('security.user.register.msg.passwordMismatch'))->toJsonResponse();
        }

        if ($lockPassword !== $lockPasswordConfirmed) {
            $this->trackRegistrationRejected("lock_password_mismatch");
            return BaseResponse::buildBadRequestErrorResponse($this->translator->trans('security.user.register.msg.lockPasswordMismatch'))->toJsonResponse();
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->trackRegistrationRejected("invalid_email");
            return BaseResponse::buildBadRequestErrorResponse($this->translator->trans('security.user.register.msg.emailSyntaxInvalid'))->toJsonResponse();
        }

        $userRepo = $this->em->getRepository(User::class);
        if (!is_null($userRepo->findOneActive())) {
            $this->trackRegistrationRejected("active_user_exists");
            return BaseResponse::buildBadRequestErrorResponse($this->translator->trans('security.user.register.msg.activeUserExists'))->toJsonResponse();
        }

        // doesn't matter if deleted or not
        $userForEmail = $userRepo->findOneByEmail($email);
        if (!empty($userForEmail)) {
            $this->trackRegistrationRejected("email_already_registered");
            return BaseResponse::buildBadRequestErrorResponse($this->translator->trans('security.user.register.msg.userWithEmailExists'))->toJsonResponse();
        }

        $hashedPassword     = $this->passwordHashingService->encode($password);
        $hashedLockPassword = $this->passwordHashingService->encode($lockPassword);

        $user = new User();
        $user->setPassword($hashedPassword);
        $user->setLockPassword($hashedLockPassword);
        $user->setEnabled(true);
        $user->setUsername($username);
        $user->setUsernameCanonical($username);
        $user->setEmail($email);
        $user->setEmailCanonical($email);

        $this->em->persist($user);
        $this->em->flush();

        // the user id exists only after the flush, and from now on it's the visitor id of this user
        $this->pendoTrackService->track(PendoTrackEventEnum::USER_REGISTRATION_COMPLETED, [
            'registration_status' => "success",
            'user_id'             => $user->getId(),
            'is_demo'             => EnvReader::isDemo(),
        ], (string) $user->getId());

        return BaseResponse::buildOkResponse()->toJsonResponse();
    }

    /**
     * @Route("/login", name="login")
     * This route is used by the {@see LexikJWTAuthenticationBundle}
     *
     * @return JsonResponse
     */
    #[JwtAuthenticationDisabledAttribute]
    public function login(): JsonResponse
    {
        return BaseResponse::buildOkResponse()->toJsonResponse();
    }

    /**
     * Checks if registration is enabled
     *
     * @return JsonResponse
     */
    #[Route("/can-register", name: 'can_register_check', methods: [Request::METHOD_GET, Request::METHOD_OPTIONS])]
    #[JwtAuthenticationDisabledAttribute]
    public function hasAnyActiveUser(): JsonResponse
    {
        $userRepo = $this->em->getRepository(User::class);
        if (!is_null($userRepo->findOneActive())) {
            $msg = $this->translator->trans('security.user.register.msg.activeUserExists');
            return BaseResponse::buildNotFoundResponse($msg)->toJsonResponse();
        }

        return BaseResponse::buildOkResponse()->toJsonResponse();
    }

    /**
     * @Route("/logout", name="logout")
     */
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    /**
     * Rejected attempt has no user yet, so it's tracked for an anonymous visitor (never with the email / username)
     *
     * @param string $failureReason
     */
    private function trackRegistrationRejected(string $failureReason): void
    {
        $this->pendoTrackService->track(PendoTrackEventEnum::USER_REGISTRATION_COMPLETED, [
            'registration_status' => "rejected",
            'failure_reason'      => $failureReason,
            'is_demo'             => EnvReader::isDemo(),
        ], PendoTrackService::ANONYMOUS_VISITOR_ID);
    }

    /**
     * The lock password is never sent
     *
     * @param string $newLockState
     * @param string $changeStatus
     * @param int    $lockedModulesCount
     */
    private function trackSystemLockStateChanged(string $newLockState, string $changeStatus, int $lockedModulesCount): void
    {
        $this->pendoTrackService->track(PendoTrackEventEnum::SYSTEM_LOCK_STATE_CHANGED, [
            'new_lock_state'       => $newLockState,
            'change_status'        => $changeStatus,
            'locked_modules_count' => $lockedModulesCount,
        ]);
    }
}