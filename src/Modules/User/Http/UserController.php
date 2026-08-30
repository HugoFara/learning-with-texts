<?php

/**
 * User Controller
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Http;

use Lwt\Shared\Http\BaseController;
use Lwt\Shared\Infrastructure\Exception\AuthException;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Language\LanguagePresets;
use Lwt\Modules\Admin\Application\Services\TtsService;
use Lwt\Modules\Admin\Application\UseCases\Theme\GetAvailableThemes;
use Lwt\Modules\User\Application\UserFacade;
use Lwt\Modules\User\Application\Services\AltchaService;
use Lwt\Modules\User\Infrastructure\AuthFormDataManager;
use Lwt\Shared\Infrastructure\Http\FlashMessageService;
use Lwt\Shared\Infrastructure\Http\ResponseInterface;
use Lwt\Shared\Infrastructure\Http\SecurityHeaders;

/**
 * Controller for user authentication operations.
 *
 * Handles login, registration, and logout functionality.
 *
 * @since 3.0.0
 */
class UserController extends BaseController
{
    /**
     * User facade instance.
     *
     * @var UserFacade
     */
    private UserFacade $userFacade;

    /**
     * Flash message service.
     *
     * @var FlashMessageService
     */
    private FlashMessageService $flash;

    /**
     * Auth form data manager.
     *
     * @var AuthFormDataManager
     */
    private AuthFormDataManager $formData;

    /**
     * ALTCHA proof-of-work captcha service.
     */
    private AltchaService $altcha;

    /**
     * Create a new UserController.
     *
     * @param UserFacade|null          $userFacade User facade (optional for BC)
     * @param FlashMessageService|null $flash      Flash message service
     * @param AuthFormDataManager|null $formData   Form data manager
     * @param AltchaService|null       $altcha     Captcha service (optional for BC)
     */
    public function __construct(
        ?UserFacade $userFacade = null,
        ?FlashMessageService $flash = null,
        ?AuthFormDataManager $formData = null,
        ?AltchaService $altcha = null
    ) {
        parent::__construct();
        $this->userFacade = $userFacade ?? $this->createDefaultFacade();
        $this->flash = $flash ?? new FlashMessageService();
        $this->formData = $formData ?? new AuthFormDataManager();
        $this->altcha = $altcha ?? AltchaService::fromEnvironment();
    }

    /**
     * Create a default UserFacade instance.
     *
     * @return UserFacade
     */
    private function createDefaultFacade(): UserFacade
    {
        $repository = new \Lwt\Modules\User\Infrastructure\MySqlUserRepository();
        return new UserFacade($repository);
    }

    /**
     * Display the login form.
     *
     * GET /login
     *
     * @return ResponseInterface|null
     */
    public function loginForm(): mixed
    {
        // If already authenticated, redirect to home
        if (Globals::isAuthenticated()) {
            return $this->redirect('/');
        }

        // Get flash error messages
        $errorMessages = $this->flash->getByTypeAndClear(FlashMessageService::TYPE_ERROR);
        $error = !empty($errorMessages) ? $errorMessages[0]['message'] : null;

        // Get persisted form data
        $username = $this->formData->getAndClearUsername();

        $this->render(__('user.login.page_title'), false);
        require __DIR__ . '/../Views/login.php';
        $this->endRender();

        return null;
    }

    /**
     * Display the packaged-client auth screen ("choose server + log in").
     *
     * Client-rendered, token-based entry flow for a cross-origin/packaged
     * client (the planned Capacitor/F-Droid app). Distinct from loginForm,
     * which is the same-origin cookie-session login.
     *
     * GET /connect
     *
     * @return ResponseInterface|null
     */
    public function clientAuthForm(): mixed
    {
        // Already signed in via the web session: nothing to connect to.
        if (Globals::isAuthenticated()) {
            return $this->redirect('/');
        }

        $this->render(__('user.login.page_title'), false);
        require __DIR__ . '/../Views/client_auth.php';
        $this->endRender();

        return null;
    }

    /**
     * Process the login form submission.
     *
     * POST /login
     *
     * @return ResponseInterface
     */
    public function login(): ResponseInterface
    {
        if (!$this->isPost()) {
            return $this->redirect('/login');
        }

        $usernameOrEmail = $this->post('username');
        $password = $this->post('password');
        $remember = $this->post('remember') === '1';

        // Basic validation
        if (empty($usernameOrEmail) || empty($password)) {
            $this->flash->error(__('user.flash.login_missing_credentials'));
            $this->formData->setUsername($usernameOrEmail);
            return $this->redirect('/login');
        }

        try {
            $user = $this->userFacade->login($usernameOrEmail, $password);

            // Set remember me cookie if requested
            if ($remember) {
                $this->setRememberCookie($user->id()->toInt());
            }

            // Drop any guest UI-language override so the account's own
            // app_language preference governs once signed in.
            if (isset($_COOKIE['lwt_lang']) && !headers_sent()) {
                setcookie('lwt_lang', '', ['expires' => time() - 3600, 'path' => '/']);
            }

            // Redirect to intended URL or home
            $redirectTo = $this->formData->getAndClearRedirectUrl('/');
            return $this->redirect($redirectTo);
        } catch (AuthException $e) {
            $this->flash->error($e->getMessage());
            $this->formData->setUsername($usernameOrEmail);
            return $this->redirect('/login');
        }
    }

    /**
     * Display the registration form.
     *
     * GET /register
     *
     * @return ResponseInterface|null
     */
    public function registerForm(): mixed
    {
        // Check if registration is enabled
        if (!$this->isRegistrationEnabled()) {
            return $this->redirect('/login');
        }

        // If already authenticated, redirect to home
        if (Globals::isAuthenticated()) {
            return $this->redirect('/');
        }

        // Get flash error messages
        $errorMessages = $this->flash->getByTypeAndClear(FlashMessageService::TYPE_ERROR);
        $error = !empty($errorMessages) ? $errorMessages[0]['message'] : null;

        // Get persisted form data
        $username = $this->formData->getAndClearUsername();
        $email = $this->formData->getAndClearEmail();

        // Stamp when the form was served, so the POST handler can reject
        // near-instant submissions (a cheap bot signal — see register()).
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['register_form_time'] = time();

        $this->render(__('user.register.page_title'), false);
        require __DIR__ . '/../Views/register.php';
        $this->endRender();

        return null;
    }

    /**
     * Process the registration form submission.
     *
     * POST /register
     *
     * @return ResponseInterface
     */
    public function register(): ResponseInterface
    {
        if (!$this->isPost()) {
            return $this->redirect('/register');
        }

        // Check if registration is enabled
        if (!$this->isRegistrationEnabled()) {
            return $this->redirect('/login');
        }

        // Bot traps (cheap first line; the captcha is the real gate). A filled
        // honeypot or a near-instant submission is almost certainly a bot, so
        // pretend it worked — redirecting to /login wastes the bot's effort
        // without revealing the trap, and no account is created.
        if ($this->looksLikeBotSubmission()) {
            unset($_SESSION['register_form_time']);
            return $this->redirect('/login');
        }

        $username = $this->post('username');
        $email = $this->post('email');
        $password = $this->post('password');
        $passwordConfirm = $this->post('password_confirm');

        // Store form data for repopulation
        $this->formData->setUsername($username);
        $this->formData->setEmail($email);

        // Basic validation. Email is optional (the username is the unique
        // identity); it is only kept as a recovery/verification channel.
        if (empty($username) || empty($password)) {
            $this->flash->error(__('user.flash.register_missing_fields'));
            return $this->redirect('/register');
        }

        // Password confirmation
        if ($password !== $passwordConfirm) {
            $this->flash->error(__('user.flash.register_passwords_mismatch'));
            return $this->redirect('/register');
        }

        // Proof-of-work captcha: the browser must return a solved challenge.
        if (!$this->altcha->verify($this->post('altcha'))) {
            $this->flash->error(__('user.flash.register_captcha_failed'));
            return $this->redirect('/register');
        }

        try {
            $user = $this->userFacade->register($username, $email, $password);

            // Send verification email (non-blocking)
            $this->userFacade->sendVerificationEmail($user);

            // Note: setCurrentUser() only updates the in-process Globals; it
            // does NOT persist a session, so the next request would still see
            // an unauthenticated user and the auth middleware would bounce
            // them back to /login anyway. Surface the outcome by sending the
            // user straight to /login with a success message and prefilled
            // username — the login view renders $_SESSION['auth_success'].
            $this->formData->clearEmail();

            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }

            // Email-less account: issue a one-time recovery code (their only
            // way back into a forgotten account) and show it once.
            if ($user->email() === null) {
                $_SESSION['lwt_recovery_code'] = $this->userFacade->generateRecoveryCode($user);
                $_SESSION['lwt_recovery_context'] = 'register';
                return $this->redirect('/register/recovery-code');
            }

            $message = __('user.flash.register_success');
            if ($user->isAdmin()) {
                $message .= ' ' . __('user.flash.register_admin_granted');
            }
            if (!$user->isEmailVerified()) {
                $message .= ' ' . __('user.flash.register_verify_email');
            }
            $_SESSION['auth_success'] = $message;
            return $this->redirect('/login');
        } catch (\InvalidArgumentException $e) {
            $this->flash->error($e->getMessage());
            return $this->redirect('/register');
        } catch (\RuntimeException $e) {
            $this->flash->error(__('user.flash.register_failed'));
            return $this->redirect('/register');
        }
    }

    /** Minimum plausible seconds between serving and submitting the form. */
    private const MIN_REGISTER_SECONDS = 2;

    /**
     * Cheap, no-friction bot heuristics for the registration POST.
     *
     * - Honeypot: the form carries a `homepage` field hidden off-screen with
     *   tabindex=-1 and autocomplete=off; a human never fills it, naive bots do.
     * - Timing: a form submitted within {@see self::MIN_REGISTER_SECONDS} of
     *   being served was almost certainly auto-filled. Only enforced when a
     *   render timestamp exists, so a lost session can't false-positive.
     *
     * The real gate is the proof-of-work captcha; this just turns away the
     * cheapest bots for free.
     */
    private function looksLikeBotSubmission(): bool
    {
        if (trim($this->post('homepage')) !== '') {
            return true;
        }
        $formTime = (int) ($_SESSION['register_form_time'] ?? 0);
        return $formTime > 0 && (time() - $formTime) < self::MIN_REGISTER_SECONDS;
    }

    /**
     * Log out the current user.
     *
     * GET /logout
     *
     * @return ResponseInterface
     */
    public function logout(): ResponseInterface
    {
        // Invalidate and clear remember me cookie
        $currentUser = $this->userFacade->getCurrentUser();
        if ($currentUser !== null) {
            $this->userFacade->invalidateRememberToken($currentUser->id()->toInt());
        }
        $this->clearRememberCookie();

        // Logout via user facade
        $this->userFacade->logout();

        // Redirect to login
        return $this->redirect('/login');
    }

    // =========================================================================
    // Profile Methods
    // =========================================================================

    /**
     * Display the user profile form.
     *
     * GET /profile
     *
     * @return ResponseInterface|null
     */
    public function profileForm(): mixed
    {
        $user = $this->userFacade->getCurrentUser();
        if ($user === null) {
            if (Globals::isMultiUserEnabled()) {
                return $this->redirect('/login');
            }

            // Single-user mode: show simplified profile page
            $this->render(__('user.profile.page_title'), true);
            require __DIR__ . '/../Views/profile_single_user.php';
            $this->endRender();
            return null;
        }

        $errorMessages = $this->flash->getByTypeAndClear(FlashMessageService::TYPE_ERROR);
        $error = !empty($errorMessages) ? $errorMessages[0]['message'] : null;

        $successMessages = $this->flash->getByTypeAndClear(FlashMessageService::TYPE_SUCCESS);
        $success = !empty($successMessages) ? $successMessages[0]['message'] : null;

        $this->render(__('user.profile.page_title'), true);
        require __DIR__ . '/../Views/profile.php';
        $this->endRender();

        return null;
    }

    // =========================================================================
    // Preferences Methods
    // =========================================================================

    /**
     * Display the user preferences form.
     *
     * GET /profile/preferences
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     *
     * @psalm-suppress UnusedVariable Variables are used in included view files
     * @psalm-suppress UnresolvableInclude View path is constructed at runtime
     */
    public function preferencesForm(array $params = []): void
    {
        $errorMessages = $this->flash->getByTypeAndClear(FlashMessageService::TYPE_ERROR);
        $error = !empty($errorMessages) ? $errorMessages[0]['message'] : null;

        $successMessages = $this->flash->getByTypeAndClear(FlashMessageService::TYPE_SUCCESS);
        $success = !empty($successMessages) ? $successMessages[0]['message'] : null;

        $settings = $this->userFacade->getUserPreferences();

        // Theme data for appearance section
        $themes = (new GetAvailableThemes())->execute();

        // TTS data
        $ttsService = new TtsService();
        $languageOptions = $ttsService->getLanguageOptions(LanguagePresets::getAll());
        $currentLanguageCode = $ttsService->getCurrentLanguageCode(LanguagePresets::getAll());

        $this->render(__('preferences.page_title'), true);

        if ($success !== null && $success !== '') {
            $this->message($success, true);
        }
        if ($error !== null && $error !== '') {
            $this->message($error, true);
        }

        require __DIR__ . '/../Views/preferences.php';
        $this->endRender();
    }

    // =========================================================================
    // Email Verification Methods
    // =========================================================================

    /**
     * Verify a user's email via token link.
     *
     * GET /verify-email?token=...
     *
     * @return ResponseInterface
     */
    public function verifyEmail(): ResponseInterface
    {
        $token = $this->param('token');

        if (empty($token)) {
            $this->flash->error(__('user.flash.verify_invalid_link'));
            return $this->redirect('/');
        }

        $user = $this->userFacade->verifyEmail($token);

        if ($user === null) {
            $this->flash->error(__('user.flash.verify_expired'));
            return $this->redirect('/');
        }

        $this->flash->success(__('user.flash.verify_success'));
        return $this->redirect('/');
    }

    /**
     * Resend email verification link.
     *
     * POST /email/resend-verification
     *
     * @return ResponseInterface
     */
    public function resendVerification(): ResponseInterface
    {
        if (!$this->isPost()) {
            return $this->redirect('/');
        }

        $user = $this->userFacade->getCurrentUser();
        if ($user === null) {
            return $this->redirect('/login');
        }

        if ($user->isEmailVerified()) {
            $this->flash->success(__('user.flash.verify_already'));
            return $this->redirect('/');
        }

        $this->userFacade->sendVerificationEmail($user);
        $this->flash->success(__('user.flash.verify_sent'));
        return $this->redirect('/');
    }

    // =========================================================================
    // Password Reset Methods
    // =========================================================================

    /**
     * Display the forgot password form.
     *
     * GET /password/forgot
     *
     * @return void
     */
    public function forgotPasswordForm(): void
    {
        // If already authenticated, redirect to home
        if (Globals::isAuthenticated()) {
            $this->redirect('/');
        }

        // Get flash messages
        $errorMessages = $this->flash->getByTypeAndClear(FlashMessageService::TYPE_ERROR);
        $error = !empty($errorMessages) ? $errorMessages[0]['message'] : null;

        $successMessages = $this->flash->getByTypeAndClear(FlashMessageService::TYPE_SUCCESS);
        $success = !empty($successMessages) ? $successMessages[0]['message'] : null;

        // Get persisted form data
        $email = $this->formData->getAndClearPasswordEmail();

        $this->render(__('user.forgot.page_title'), false);
        require __DIR__ . '/../Views/forgot_password.php';
        $this->endRender();
    }

    /**
     * Process the forgot password form submission.
     *
     * POST /password/forgot
     *
     * @return void
     */
    public function forgotPassword(): void
    {
        if (!$this->isPost()) {
            $this->redirect('/password/forgot');
        }

        $email = $this->post('email');

        if (empty($email)) {
            $this->flash->error(__('user.flash.forgot_missing_email'));
            $this->redirect('/password/forgot');
        }

        // Always show success message (prevents email enumeration)
        $this->userFacade->requestPasswordReset($email);

        $this->flash->success(__('user.flash.forgot_sent'));
        $this->redirect('/password/forgot');
    }

    /**
     * Display the reset password form.
     *
     * GET /password/reset?token=xxx
     *
     * @return void
     */
    public function resetPasswordForm(): void
    {
        // If already authenticated, redirect to home
        if (Globals::isAuthenticated()) {
            $this->redirect('/');
        }

        $token = $this->get('token');

        if (empty($token)) {
            $this->flash->error(__('user.flash.reset_invalid_token'));
            $this->redirect('/password/forgot');
        }

        // Validate token before showing form
        if (!$this->userFacade->validatePasswordResetToken($token)) {
            $this->flash->error(__('user.flash.reset_expired'));
            $this->redirect('/password/forgot');
        }

        // Get flash error messages
        $errorMessages = $this->flash->getByTypeAndClear(FlashMessageService::TYPE_ERROR);
        $error = !empty($errorMessages) ? $errorMessages[0]['message'] : null;

        $this->render(__('user.reset.page_title'), false);
        require __DIR__ . '/../Views/reset_password.php';
        $this->endRender();
    }

    /**
     * Process the reset password form submission.
     *
     * POST /password/reset
     *
     * @return void
     */
    public function resetPassword(): void
    {
        if (!$this->isPost()) {
            $this->redirect('/password/forgot');
        }

        $token = $this->post('token');
        $password = $this->post('password');
        $passwordConfirm = $this->post('password_confirm');

        if (empty($token)) {
            $this->flash->error(__('user.flash.reset_token_invalid'));
            $this->redirect('/password/forgot');
        }

        if (empty($password)) {
            $this->flash->error(__('user.flash.reset_missing_password'));
            $this->redirect('/password/reset?token=' . urlencode($token));
        }

        if ($password !== $passwordConfirm) {
            $this->flash->error(__('user.flash.reset_passwords_mismatch'));
            $this->redirect('/password/reset?token=' . urlencode($token));
        }

        try {
            $success = $this->userFacade->completePasswordReset($token, $password);

            if ($success) {
                $this->flash->success(__('user.flash.reset_success'));
                $this->redirect('/login');
            } else {
                $this->flash->error(__('user.flash.reset_expired'));
                $this->redirect('/password/forgot');
            }
        } catch (\InvalidArgumentException $e) {
            $this->flash->error($e->getMessage());
            $this->redirect('/password/reset?token=' . urlencode($token));
        }
    }

    /**
     * Show a freshly issued recovery code exactly once.
     *
     * GET /register/recovery-code
     *
     * The code is read from (and cleared out of) the session, so a refresh or a
     * direct visit shows nothing and bounces to login.
     *
     * @return mixed Redirect response, or null after rendering.
     */
    public function recoveryCodeShown(): mixed
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $recoveryCode = (string) ($_SESSION['lwt_recovery_code'] ?? '');
        $recoveryContext = (string) ($_SESSION['lwt_recovery_context'] ?? 'register');
        unset($_SESSION['lwt_recovery_code'], $_SESSION['lwt_recovery_context']);

        if ($recoveryCode === '') {
            return $this->redirect('/login');
        }
        if ($recoveryContext === '') {
            $recoveryContext = 'register';
        }

        $this->render(__('user.recovery.page_title'), false);
        require __DIR__ . '/../Views/recovery_code.php';
        $this->endRender();

        return null;
    }

    /**
     * Show the "reset with recovery code" form.
     *
     * GET /password/recover
     *
     * @return mixed Redirect response, or null after rendering.
     */
    public function recoverWithCodeForm(): mixed
    {
        if (Globals::isAuthenticated()) {
            return $this->redirect('/');
        }

        $errorMessages = $this->flash->getByTypeAndClear(FlashMessageService::TYPE_ERROR);
        $error = !empty($errorMessages) ? $errorMessages[0]['message'] : null;
        $username = $this->formData->getAndClearUsername();

        $this->render(__('user.recovery.reset_page_title'), false);
        require __DIR__ . '/../Views/recover_password.php';
        $this->endRender();

        return null;
    }

    /**
     * Process the "reset with recovery code" form.
     *
     * POST /password/recover
     *
     * @return ResponseInterface
     */
    public function recoverWithCode(): ResponseInterface
    {
        if (!$this->isPost()) {
            return $this->redirect('/password/recover');
        }

        $username = $this->post('username');
        $code = $this->post('recovery_code');
        $password = $this->post('password');
        $passwordConfirm = $this->post('password_confirm');

        $this->formData->setUsername($username);

        if (empty($username) || empty($code) || empty($password)) {
            $this->flash->error(__('user.flash.recover_missing_fields'));
            return $this->redirect('/password/recover');
        }

        if ($password !== $passwordConfirm) {
            $this->flash->error(__('user.flash.reset_passwords_mismatch'));
            return $this->redirect('/password/recover');
        }

        try {
            $newCode = $this->userFacade->resetPasswordWithRecoveryCode($username, $code, $password);

            // Reset succeeded: show the rotated code once, then on to login.
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $_SESSION['lwt_recovery_code'] = $newCode;
            $_SESSION['lwt_recovery_context'] = 'reset';
            return $this->redirect('/register/recovery-code');
        } catch (\InvalidArgumentException $e) {
            $this->flash->error($e->getMessage());
            return $this->redirect('/password/recover');
        }
    }

    /**
     * Try to restore session from remember-me cookie.
     *
     * This method is called during session bootstrap to check if
     * the user has a valid remember-me cookie and restore their session.
     *
     * @return bool True if session was restored, false otherwise
     */
    public function tryRestoreFromRememberCookie(): bool
    {
        // Check if already authenticated
        if (Globals::isAuthenticated()) {
            return true;
        }

        // Check for remember cookie
        $token = filter_input(INPUT_COOKIE, 'lwt_remember') ?? '';
        if (empty($token)) {
            return false;
        }

        // Validate token and get user
        $user = $this->userFacade->validateRememberToken($token);
        if ($user === null) {
            // Invalid/expired token - clear the cookie
            $this->clearRememberCookie();
            return false;
        }

        // Restore the session
        $this->userFacade->setCurrentUser($user);

        // Regenerate session ID for security
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Optionally refresh the token and cookie to extend the session
        $this->setRememberCookie($user->id()->toInt());

        return true;
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Check if user registration is enabled.
     *
     * @return bool
     */
    private function isRegistrationEnabled(): bool
    {
        return \Lwt\Shared\Infrastructure\Database\Settings::getWithDefault(
            'set-allow-registration'
        ) === '1';
    }

    /**
     * Set a "remember me" cookie with persistent token storage.
     *
     * The token is stored in the database and set as a cookie.
     * When the user returns, the token can be validated to restore the session.
     *
     * @param int $userId The user ID
     *
     * @return void
     */
    private function setRememberCookie(int $userId): void
    {
        $days = 30;
        $expires = time() + ($days * 24 * 60 * 60);

        // Generate and store token in database
        $token = $this->userFacade->setRememberToken($userId, $days);

        // Set cookie with secure flags
        setcookie(
            'lwt_remember',
            $token,
            [
                'expires' => $expires,
                'path' => '/',
                'secure' => SecurityHeaders::isSecureConnection(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Clear the "remember me" cookie.
     *
     * @return void
     */
    private function clearRememberCookie(): void
    {
        setcookie(
            'lwt_remember',
            '',
            [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => SecurityHeaders::isSecureConnection(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }
}
