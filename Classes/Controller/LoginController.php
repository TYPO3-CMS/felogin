<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\FrontendLogin\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\LoginType;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\FrontendLogin\Configuration\RedirectConfiguration;
use TYPO3\CMS\FrontendLogin\Event\BeforeRedirectEvent;
use TYPO3\CMS\FrontendLogin\Event\LoginConfirmedEvent;
use TYPO3\CMS\FrontendLogin\Event\LoginErrorOccurredEvent;
use TYPO3\CMS\FrontendLogin\Event\LogoutConfirmedEvent;
use TYPO3\CMS\FrontendLogin\Event\ModifyLoginFormViewEvent;
use TYPO3\CMS\FrontendLogin\Redirect\RedirectHandler;
use TYPO3\CMS\FrontendLogin\Redirect\ServerRequestHandler;
use TYPO3\CMS\FrontendLogin\Service\UserService;

/**
 * Used for plugin login
 */
class LoginController extends AbstractLoginFormController
{
    public const MESSAGEKEY_DEFAULT = 'welcome';
    public const MESSAGEKEY_ERROR = 'error';
    public const MESSAGEKEY_LOGOUT = 'logout';

    protected string $loginType = '';
    protected string $redirectUrl = '';
    protected RedirectConfiguration $configuration;
    protected UserAspect $userAspect;
    protected bool $showCookieWarning = false;

    public function __construct(
        protected RedirectHandler $redirectHandler,
        protected ServerRequestHandler $requestHandler,
        protected UserService $userService
    ) {
        $this->userAspect = GeneralUtility::makeInstance(Context::class)->getAspect('frontend.user');
    }

    /**
     * Initialize redirects
     */
    public function initializeAction(): void
    {
        $this->loginType = (string)$this->requestHandler->getPropertyFromGetAndPost('logintype');
        $this->configuration = RedirectConfiguration::fromSettings($this->settings);

        if ($this->isLoginOrLogoutInProgress() && !$this->isRedirectDisabled()) {
            if ($this->userAspect->isLoggedIn() && $this->userService->cookieWarningRequired()) {
                $this->showCookieWarning = true;
                return;
            }

            $this->redirectUrl = $this->redirectHandler->processRedirect(
                $this->loginType,
                $this->configuration,
                $this->request->hasArgument('redirectReferrer') ? $this->request->getArgument('redirectReferrer') : ''
            );
        }
    }

    /**
     * Show login form
     */
    public function loginAction(): ResponseInterface
    {
        if ($this->isLogoutSuccessful()) {
            $this->eventDispatcher->dispatch(new LogoutConfirmedEvent($this, $this->view));
        } elseif ($this->hasLoginErrorOccurred()) {
            $this->eventDispatcher->dispatch(new LoginErrorOccurredEvent());
        }

        if (($forwardResponse = $this->handleLoginForwards()) !== null) {
            return $forwardResponse;
        }
        if (($redirectResponse = $this->handleRedirect()) !== null) {
            return $redirectResponse;
        }

        $this->eventDispatcher->dispatch(new ModifyLoginFormViewEvent($this->view));

        $this->view->assignMultiple(
            [
                'cookieWarning' => $this->showCookieWarning,
                'messageKey' => $this->getStatusMessageKey(),
                'permaloginStatus' => $this->getPermaloginStatus(),
                'redirectURL' => $this->redirectHandler->getLoginFormRedirectUrl($this->configuration, $this->isRedirectDisabled()),
                'redirectReferrer' => $this->request->hasArgument('redirectReferrer') ? (string)$this->request->getArgument('redirectReferrer') : '',
                'referer' => $this->requestHandler->getPropertyFromGetAndPost('referer'),
                'noRedirect' => $this->isRedirectDisabled(),
                'requestToken' => RequestToken::create('core/user-auth/fe')
                    ->withMergedParams(['pid' => implode(',', $this->getStorageFolders())]),
            ]
        );

        return $this->htmlResponse();
    }

    /**
     * User overview for logged in users
     */
    public function overviewAction(bool $showLoginMessage = false): ResponseInterface
    {
        if (!$this->userAspect->isLoggedIn()) {
            return new ForwardResponse('login');
        }

        $this->eventDispatcher->dispatch(new LoginConfirmedEvent($this, $this->view));
        if (($redirectResponse = $this->handleRedirect()) !== null) {
            return $redirectResponse;
        }

        $this->view->assignMultiple(
            [
                'cookieWarning' => $this->showCookieWarning,
                'user' => $this->userService->getFeUserData(),
                'showLoginMessage' => $showLoginMessage,
            ]
        );

        return $this->htmlResponse();
    }

    /**
     * Show logout form
     */
    public function logoutAction(int $redirectPageLogout = 0): ResponseInterface
    {
        if (($redirectResponse = $this->handleRedirect()) !== null) {
            return $this->handleRedirect();
        }

        $this->view->assignMultiple(
            [
                'cookieWarning' => $this->showCookieWarning,
                'user' => $this->userService->getFeUserData(),
                'noRedirect' => $this->isRedirectDisabled(),
                'actionUri' => $this->redirectHandler->getLogoutFormRedirectUrl($this->configuration, $redirectPageLogout, $this->isRedirectDisabled()),
            ]
        );

        return $this->htmlResponse();
    }

    /**
     * Handles the redirect when $this->redirectUrl is not empty
     */
    protected function handleRedirect(): ?ResponseInterface
    {
        if ($this->redirectUrl !== '') {
            $this->eventDispatcher->dispatch(new BeforeRedirectEvent($this->loginType, $this->redirectUrl));
            return $this->redirectToUri($this->redirectUrl);
        }
        return null;
    }

    /**
     * Handle forwards to overview and logout actions from login action
     */
    protected function handleLoginForwards(): ?ResponseInterface
    {
        if ($this->shouldRedirectToOverview()) {
            return (new ForwardResponse('overview'))->withArguments(['showLoginMessage' => true]);
        }

        if ($this->userAspect->isLoggedIn()) {
            return (new ForwardResponse('logout'))->withArguments(['redirectPageLogout' => $this->settings['redirectPageLogout']]);
        }

        return null;
    }

    /**
     * The permanent login checkbox should only be shown if permalogin is not deactivated (-1),
     * not forced to be always active (2) and lifetime is greater than 0
     */
    protected function getPermaloginStatus(): int
    {
        $permaLogin = (int)$GLOBALS['TYPO3_CONF_VARS']['FE']['permalogin'];

        return $this->isPermaloginDisabled($permaLogin) ? -1 : $permaLogin;
    }

    protected function isPermaloginDisabled(int $permaLogin): bool
    {
        return $permaLogin > 1
               || (int)($this->settings['showPermaLogin'] ?? 0) === 0
               || $GLOBALS['TYPO3_CONF_VARS']['FE']['lifetime'] === 0;
    }

    /**
     * Redirect to overview on login successful and setting showLogoutFormAfterLogin disabled
     */
    protected function shouldRedirectToOverview(): bool
    {
        return $this->userAspect->isLoggedIn()
               && ($this->loginType === LoginType::LOGIN)
               && !($this->settings['showLogoutFormAfterLogin'] ?? 0);
    }

    /**
     * Return message key based on user login status
     */
    protected function getStatusMessageKey(): string
    {
        $messageKey = self::MESSAGEKEY_DEFAULT;
        if ($this->hasLoginErrorOccurred()) {
            $messageKey = self::MESSAGEKEY_ERROR;
        } elseif ($this->loginType === LoginType::LOGOUT) {
            $messageKey = self::MESSAGEKEY_LOGOUT;
        }

        return $messageKey;
    }

    protected function isLoginOrLogoutInProgress(): bool
    {
        return $this->loginType === LoginType::LOGIN || $this->loginType === LoginType::LOGOUT;
    }

    /**
     * Is redirect disabled by setting or noredirect parameter
     */
    public function isRedirectDisabled(): bool
    {
        return
            $this->request->hasArgument('noredirect')
            || ($this->settings['noredirect'] ?? false)
            || ($this->settings['redirectDisable'] ?? false);
    }

    protected function isLogoutSuccessful(): bool
    {
        return $this->loginType === LoginType::LOGOUT && !$this->userAspect->isLoggedIn();
    }

    protected function hasLoginErrorOccurred(): bool
    {
        return $this->loginType === LoginType::LOGIN && !$this->userAspect->isLoggedIn();
    }
}
