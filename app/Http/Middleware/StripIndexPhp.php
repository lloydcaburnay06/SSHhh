<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Old bookmarks and installed apps may still open /index.php (the pre-Laravel entry point).
 * Laravel would then build every URL as /index.php/..., which breaks images, the service
 * worker and links, so browsers are sent to the clean address instead.
 *
 * Done here rather than in .htaccess: behind a tunnel Apache only knows its own port and
 * scheme, while this redirect is relative and keeps the address the browser used.
 */
class StripIndexPhp
{
    public function handle(Request $request, Closure $next): Response
    {
        // The base URL includes the script name only when the address itself contains it
        if ($request->isMethodSafe() && $request->getBaseUrl() !== $request->getBasePath()) {
            $query = $request->getQueryString();
            $target = $request->getBasePath().$request->getPathInfo().($query !== null ? '?'.$query : '');

            // Relative on purpose: url() would put /index.php straight back
            return new RedirectResponse($target, 301);
        }

        return $next($request);
    }
}
