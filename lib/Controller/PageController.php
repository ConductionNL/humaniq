<?php

/**
 * Hrmq Page Controller
 *
 * Renders the SPA shell that mounts the @conduction/nextcloud-vue manifest
 * renderer (CnAppRoot) and serves the bundled app manifest (ADR-024 §4). The
 * app stores no data of its own — the Timesheet/Expense pages are declarative
 * `type: "index"` / `type: "detail"` pages that read and write the hrmq
 * OpenRegister register directly via the library's object store; this
 * controller is pure SPA-shell glue.
 *
 * @category Controller
 * @package  OCA\Hrmq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude framework glue — SPA shell + bundled-manifest passthrough; no business behaviour
 */

declare(strict_types=1);

namespace OCA\Hrmq\Controller;

use OCA\Hrmq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Renders the main SPA template and serves the bundled app manifest.
 *
 * @spec exclude framework glue — SPA shell + manifest passthrough; no business behaviour
 */
class PageController extends Controller
{


    /**
     * Constructor.
     *
     * @param IRequest     $request     The request object.
     * @param IUserSession $userSession The user session.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()


    /**
     * The decoded manifest, memoised for the request lifecycle so repeat calls
     * within one request do not re-read + re-parse the file.
     *
     * @var array<string, mixed>|null
     */
    private ?array $manifestCache = null;


    /**
     * Render the main SPA page.
     *
     * @return TemplateResponse
     *
     * @spec exclude framework glue — returns the static index TemplateResponse that boots the Vue SPA
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        return new TemplateResponse(Application::APP_ID, 'index');

    }//end index()


    /**
     * Serve the SPA for deep links (Vue history mode). Delegates to index().
     *
     * @return TemplateResponse
     *
     * @spec exclude framework glue — deep-link catch-all delegating to index() so Vue Router resolves the path
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function catchAll(): TemplateResponse
    {
        return $this->index();

    }//end catchAll()


    /**
     * Return the bundled app manifest as JSON (ADR-024 §4).
     *
     * The manifest is a build-time, versioned, immutable-per-deploy artifact, so
     * the response carries an ETag derived from the app id + version and a
     * revalidating Cache-Control header. When the client sends a matching
     * `If-None-Match` the framework short-circuits with a `304 Not Modified`,
     * avoiding a re-download of the full payload. The decoded manifest is
     * memoised for the request lifecycle so repeat calls within one request do
     * not re-read + re-parse the file.
     *
     * @return JSONResponse
     *
     * @spec exclude framework glue — returns the bundled src/manifest.json blob unchanged
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function manifest(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        if ($this->manifestCache === null) {
            $manifestPath        = __DIR__.'/../../src/manifest.json';
            $manifestJson        = (string) file_get_contents($manifestPath);
            $this->manifestCache = (array) json_decode($manifestJson, associative: true);
        }

        $response = new JSONResponse($this->manifestCache);

        // ETag ties cache validity to the deployed app version so a new release
        // busts the cache; Cache-Control lets clients revalidate rather than
        // re-download when the ETag is unchanged.
        $version = (string) ($this->manifestCache['version'] ?? '0');
        $response->setETag(md5(Application::APP_ID.':'.$version));
        $response->cacheFor(3600, public: false, immutable: false);

        return $response;

    }//end manifest()


}//end class
