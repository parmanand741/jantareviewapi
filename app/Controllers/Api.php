<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Legacy\AdminHandlers;
use App\Libraries\Legacy\Admin;
use App\Libraries\Legacy\ApiResponseException;
use App\Libraries\Legacy\Config;
use App\Libraries\Legacy\PublicHandlers;
use App\Libraries\Legacy\RateLimit;
use App\Libraries\Legacy\Otp;
use App\Libraries\Legacy\Request;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Action-compatible API adapter for the legacy frontend.
 *
 * The action names and JSON envelopes remain unchanged; CI4 owns routing,
 * sessions, error handling, and response headers.
 */
final class Api extends BaseController
{
    public function index(): ResponseInterface
    {
        try {
            // CORS and the baseline security headers are granted before anything
            // can answer: the admin console probes ?action=health cross-origin on
            // page load, while it still has no session and no CSRF token.
            $origin = (string) $this->request->getHeaderLine('Origin');
            $this->applyCors($origin);

            // An uptime probe has to answer even when the configuration is the
            // thing that is broken, so this is served before Request — which
            // loads and validates the whole Review config to derive an identity.
            if ($this->request->getMethod() === 'GET'
                && (string) ($this->request->getGet('action') ?? '') === 'health') {
                PublicHandlers::health();
            }

            $request = new Request($this->request);
            if ($request->method === 'OPTIONS') {
                return $this->response->setStatusCode(204);
            }
            if ($request->method === 'POST' && ($request->origin === '' || !$this->isAllowedOrigin($request->origin))) {
                return $this->json(['success' => false, 'error' => 'Origin is not allowed.'], 403);
            }
            if ($request->origin !== '' && !$this->isAllowedOrigin($request->origin)) {
                return $this->json(['success' => false, 'error' => 'Origin is not allowed.'], 403);
            }

            if ($request->method === 'GET') {
                if ($request->action === 'csrf') {
                    return $this->json([
                        'success' => true,
                        'csrfToken' => csrf_hash(),
                    ], 200);
                }
                Config::load();
                $this->throttleReads($request);
                $this->dispatchGet($request);
            } elseif ($request->method === 'POST') {
                Config::load();
                $this->throttleReads($request);
                $this->dispatchPost($request);
            } else {
                \App\Libraries\Legacy\Response::error('Method not allowed.', 405);
            }
        } catch (ApiResponseException $e) {
            return $this->json($e->payload, $e->status);
        } catch (Throwable $e) {
            log_message('error', 'Review API failure: {message}', ['message' => $e->getMessage()]);
            $status = $e instanceof \GuzzleHttp\Exception\TransferException ? 503 : 500;
            if ($e instanceof \Google\Service\Exception && $e->getCode() === 403) {
                $status = 503;
            }
            $debug = (bool) Config::get('expose_errors', false);
            return $this->json([
                'success' => false,
                'error' => $debug
                    ? $e->getMessage()
                    : ($status === 503
                    ? 'The data service is temporarily unavailable. Please try again.'
                    : 'Internal server error.'),
            ], $status);
        }

        return $this->json(['success' => false, 'error' => 'No response.'], 500);
    }

    /**
     * Reads are the cheapest way to burn the Google Sheets quota and to scrape
     * the whole dataset. Capped per address rather than per identifier: a
     * shared CGNAT exit still gets its own generous budget.
     */
    private function throttleReads(Request $request): void
    {
        $reads = $request->method === 'GET'
            ? ['compliance', 'platforms', 'info', '']
            : ['getReviews', 'getStats', 'getTransparencyLog', 'getReviewHistory', 'getTransparencyReport'];

        if (!in_array($request->action, $reads, true)) {
            return;
        }
        if (!RateLimit::checkLocal('read|' . $request->ip, (int) Config::get('read_per_minute', 120), 60)) {
            \App\Libraries\Legacy\Response::error('Too many requests from your address. Please slow down.', 429);
        }
    }

    private function dispatchGet(Request $request): never
    {
        match ($request->action) {
            'health' => PublicHandlers::health(),
            'compliance' => PublicHandlers::getCompliance(),
            'info', '' => PublicHandlers::info(),
            'platforms' => PublicHandlers::getPlatforms(),
            default => \App\Libraries\Legacy\Response::error('Unknown GET action.', 404),
        };
    }

    private function dispatchPost(Request $request): never
    {
        match ($request->action) {
            'adminLogin' => $this->adminLogin($request),
            'adminLogout' => $this->adminLogout(),
            'adminSession' => $this->adminSession(),
            'submitReview' => PublicHandlers::submitReview($request),
            'checkProductUrl' => PublicHandlers::checkProductUrl($request),
            'requestReviewOtp' => Otp::request($request, (string) (($request->payload['data']['email'] ?? ''))),
            'verifyReviewOtp' => Otp::verify(
                $request,
                (string) ($request->payload['challengeId'] ?? ''),
                (string) ($request->payload['code'] ?? '')
            ),
            'getReviews' => PublicHandlers::getReviews(),
            'vote' => PublicHandlers::vote($request),
            'report' => PublicHandlers::report($request),
            'getTransparencyLog' => PublicHandlers::getTransparencyLog(),
            'getReviewHistory' => PublicHandlers::getReviewHistory($request),
            'getTransparencyReport' => PublicHandlers::getTransparencyReport(),
            'getStats' => PublicHandlers::getStats(),
            'fileGrievance' => PublicHandlers::fileGrievance($request),
            'submitSuggestion' => PublicHandlers::submitSuggestion($request),
            'warmUp' => PublicHandlers::warmUp(),
            'adminGetDashboard' => AdminHandlers::getDashboard($request),
            'adminDeleteReview' => AdminHandlers::deleteReview($request),
            'adminRestoreReview' => AdminHandlers::restoreReview($request),
            'adminRestoreFromDeleted' => AdminHandlers::restoreFromDeleted($request),
            'adminListGrievances' => AdminHandlers::listGrievances($request),
            'adminListSuggestions' => AdminHandlers::listSuggestions($request),
            'adminUpdateSuggestionStatus' => AdminHandlers::updateSuggestionStatus($request),
            'adminAcknowledgeGrievance' => AdminHandlers::acknowledgeGrievance($request),
            'adminResolveGrievance' => AdminHandlers::resolveGrievance($request),
            default => \App\Libraries\Legacy\Response::error('Unknown action.', 404),
        };
    }

    private function adminLogin(Request $request): never
    {
        if (!Admin::login($request->payload['adminKey'] ?? null)) {
            \App\Libraries\Legacy\Response::error('Unauthorized.', 401);
        }

        \App\Libraries\Legacy\Response::ok(['message' => 'Authenticated.']);
    }

    private function adminLogout(): never
    {
        Admin::logout();
        \App\Libraries\Legacy\Response::ok(['message' => 'Logged out.']);
    }

    private function adminSession(): never
    {
        \App\Libraries\Legacy\Response::ok(['authenticated' => Admin::isAuthenticated()]);
    }

    private function applyCors(string $origin): void
    {
        $allowed = (array) config('Review')->allowedOrigins;
        if ($origin !== '' && in_array($origin, $allowed, true)) {
            $this->response
                ->setHeader('Access-Control-Allow-Origin', $origin)
                ->setHeader('Access-Control-Allow-Credentials', 'true')
                ->setHeader('Vary', 'Origin')
                ->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
                ->setHeader('Access-Control-Allow-Headers', 'Content-Type, X-CSRF-TOKEN')
                ->setHeader('Access-Control-Max-Age', '86400');
        }
        $this->response
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->setHeader('Cache-Control', 'no-store');
    }

    private function json(array $payload, int $status): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    private function isAllowedOrigin(string $origin): bool
    {
        return in_array($origin, (array) config('Review')->allowedOrigins, true);
    }
}
