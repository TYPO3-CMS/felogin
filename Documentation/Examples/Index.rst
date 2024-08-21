.. include:: /Includes.rst.txt

.. _examples:

========
Examples
========

In this section some common situations are described.


.. _login-and-back-to-original-page:

Send visitors to login page and redirect to original page
=========================================================

A common situation is that visitors who go to a page with access
restrictions should go to a login page first and after logging in
should be send back to the page they originally requested.

Assume we have a login page with id `2`.

Using TypoScript we can still display links to access restricted pages
and send visitors to the login page:

.. code-block:: typoscript

   config {
       typolinkLinkAccessRestrictedPages = 2
       typolinkLinkAccessRestrictedPages_addParams = &return_url=###RETURN_URL###
   }

On the login page the login form must be configured to redirect to the
original page:

.. code-block:: typoscript

   plugin.tx_felogin_login.settings.redirectMode = getpost

(This option can also be set in the flexform configuration of the
felogin content element)

If visitors will directly enter the URL of an access restricted page
they will be sent to the first page in the rootline to which they have
access. Sending those direct visits to a login page is not a job of
the felogin plugin, but requires a custom page-not-found handler. Please
 :ref:`see <felogin-how-to-implement-missing-custom-error-handler>`.


.. _login-link-visibility:

Login link visible when not logged in and logout link visible when logged in
============================================================================

Again TypoScript will help you out. The page with the login form has
id=2:

.. code-block:: typoscript

   10 = TEXT
   10 {
       value = Login
       typolink.parameter = 2
   }
   [frontend.user.isLoggedIn]
       10.value = Logout
       10.typolink.additionalParams = &logintype=logout
   [end]

Of course there can be solutions with :typoscript:`HMENU` items, etc.


.. _felogin-how-to-implement-missing-custom-error-handler:

How to implement the missing custom error handler
=================================================



..  rst-class:: bignums

#.  You need the following site settings in the error handling

    .. image:: ../Images/felogin_site_settings_error_handling.png


#.  Look up the page id where the felogin form is placed

    This page id we will need in the next step.

#.  Create a new error handler :file:`LoginErrorRedirectHandler.php`

    Since there is no proper way how to do it otherwise we put in the page id
    of the login form hard coded into the :file:`LoginErrorRedirectHandler.php`.

    Imagine our page id where the felogin form is located is 656 then the
    :file:`LoginErrorRedirectHandler.php` would look like:

    ..  code-block:: php
        :caption: EXT:sitepackage/Classes/Error/PageErrorHandler/LoginErrorRedirectHandler.php

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

         namespace MyVendor\MySitePackage\Error\PageErrorHandler;

         use Psr\Http\Message\ResponseFactoryInterface;
         use Psr\Http\Message\ResponseInterface;
         use Psr\Http\Message\ServerRequestInterface;
         use TYPO3\CMS\Core\Context\Context;
         use TYPO3\CMS\Core\Controller\ErrorPageController;
         use TYPO3\CMS\Core\Error\PageErrorHandler\PageErrorHandlerInterface;
         use TYPO3\CMS\Core\Http\HtmlResponse;
         use TYPO3\CMS\Core\Http\RedirectResponse;
         use TYPO3\CMS\Core\LinkHandling\LinkService;
         use TYPO3\CMS\Core\Site\Entity\Site;
         use TYPO3\CMS\Core\Utility\GeneralUtility;
         use TYPO3\CMS\Frontend\Page\PageAccessFailureReasons;

        /**
         * An error handler that redirects to a configured page, where the login process is handled. Passes a configurable
         * url parameter (`return_url` or `redirect_url`) to the target page.
         */
        class RedirectLoginErrorHandler implements PageErrorHandlerInterface
        {
            protected int $loginRedirectPid = 0;
            protected int $statusCode = 0;
            protected string $loginRedirectParameter = '';
            protected Context $context;
            protected ResponseFactoryInterface $responseFactory;
            protected LinkService $linkService;

            public function __construct(int $statusCode, array $configuration)
            {
                // TODO: Replace with $siteSettings[...] or something else
                $configuration = ['loginRedirectTarget' => 't3://page?uid=656', 'loginRedirectParameter' => 'return_url'];

                $this->statusCode = $statusCode;
                $this->context = GeneralUtility::makeInstance(Context::class);
                $this->responseFactory = GeneralUtility::makeInstance(ResponseFactoryInterface::class);
                $this->linkService = GeneralUtility::makeInstance(LinkService::class);

                $urlParams = $this->linkService->resolve($configuration['loginRedirectTarget'] ?? '');
                $this->loginRedirectPid = (int)($urlParams['pageuid'] ?? 0);
                $this->loginRedirectParameter = $configuration['loginRedirectParameter'] ?? 'return_url';
            }

            public function handlePageError(
                ServerRequestInterface $request,
                string $message,
                array $reasons = []
            ): ResponseInterface {
                $this->checkHandlerConfiguration();

                if ($this->shouldHandleRequest($reasons)) {
                    return $this->handleLoginRedirect($request);
                }

                // Show general error message with a 403 HTTP statuscode
                return $this->getGenericAccessDeniedResponse($message);
            }

            private function getGenericAccessDeniedResponse(string $reason): ResponseInterface
            {
                $content = GeneralUtility::makeInstance(ErrorPageController::class)->errorAction(
                    'Page Not Found',
                    'The page did not exist or was inaccessible.' . ($reason ? ' Reason: ' . $reason : ''),
                    0,
                    $this->statusCode,
                );
                return new HtmlResponse($content, $this->statusCode);
            }

            private function handleLoginRedirect(ServerRequestInterface $request): ResponseInterface
            {
                if ($this->isLoggedIn()) {
                    return $this->getGenericAccessDeniedResponse(
                        'The requested page was not accessible with the provided credentials'
                    );
                }

                /** @var Site $site */
                $site = $request->getAttribute('site');
                $language = $request->getAttribute('language');

                $loginUrl = $site->getRouter()->generateUri(
                    $this->loginRedirectPid,
                    [
                        '_language' => $language,
                        $this->loginRedirectParameter => (string)$request->getUri(),
                    ]
                );

                return new RedirectResponse($loginUrl);
            }

            private function shouldHandleRequest(array $reasons): bool
            {
                if (!isset($reasons['code'])) {
                    return false;
                }

                $accessDeniedReasons = [
                    PageAccessFailureReasons::ACCESS_DENIED_PAGE_NOT_RESOLVED,
                    PageAccessFailureReasons::ACCESS_DENIED_SUBSECTION_NOT_RESOLVED,
                ];
                $isAccessDenied = in_array($reasons['code'], $accessDeniedReasons, true);

                return $isAccessDenied || $this->isSimulatedBackendGroup();
            }

            private function isLoggedIn(): bool
            {
                return $this->context->getPropertyFromAspect('frontend.user', 'isLoggedIn') || $this->isSimulatedBackendGroup();
            }

            protected function isSimulatedBackendGroup(): bool
            {
                // look for special "any group"
                return $this->context->getPropertyFromAspect('backend.user', 'isLoggedIn')
                    && $this->context->getPropertyFromAspect('frontend.user', 'groupIds')[1] === -2;
            }

            private function checkHandlerConfiguration(): void
            {
                if ($this->loginRedirectPid === 0) {
                    throw new \RuntimeException('No loginRedirectTarget configured for LoginRedirect errorhandler', 1700813537);
                }

                if ($this->statusCode !== 403) {
                    throw new \RuntimeException('Invalid HTTP statuscode ' . $this->statusCode . ' for LoginRedirect errorhandler', 1700813545);
                }
            }
        }

#.  In your felogin plugin make sure you selected getpost as first redirect mode.

    .. image:: ../Images/SettingsRedirectCustomErrorHandler.png

    And be sure that you selected a page where to redirect to after a
    successful login.

#.  Test if the handler works

    Clear the caches under Maintenance, all caches in the docheader and
    open a restricted page in the incognito browser window. You try to
    access the page https://mysite.ddev.site:8443/restricted/page.
    When everything was configured correctly and if you are not logged in
    yet then you should be redirected to https://mysite.ddev.site:8443/login
    the login page (in our example pid 656). When you correctly type in the
    frontend user credentials then you should be redirected to
    https://mysite.ddev.site:8443/restricted/page the page where you
    wanted to get initially.

    ..  hint::

        Don't get confused when the url
        https://mysite.ddev.site:8443/restricted/page will look like this
        https://mysite.ddev.ddev.site:8443/login?return_url=https%3A%2F%2Fmysite.ddev.ddev.site%3A8443%2Frestricted%2Fpage&cHash=d0e92f9f9f7b3ca98a2e5e688ad22de9
        when you want to access the restricted page in the first place.

#.  Thank the person that created the patch for you that hopefully gets
    merged into the core in v13.

    See `[FEATURE] Introduce ErrorHandler for 403 errors with redirect
    option <https://review.typo3.org/c/Packages/TYPO3.CMS/+/81945>`__ .

    ..  todo::

        If `[FEATURE] Introduce ErrorHandler for 403 errors with redirect
        option <https://review.typo3.org/c/Packages/TYPO3.CMS/+/81945>`__
        gets merged describe new error handler here.