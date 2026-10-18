<?php

declare(strict_types=1);

/**
 * Reject requests that did not originate from this dashboard.
 *
 * No route had any Origin, Referer or Sec-Fetch check, so `/api/env?raw=1`
 * (which returns the whole .env) and `?reveal=<key>` were readable by any page
 * the operator happened to visit — DNS rebinding is enough, because the
 * attacker's page resolves to 127.0.0.1 and the browser then treats it as
 * same-origin at the network layer.
 *
 * A browser always sends Sec-Fetch-Site, and sends Origin on anything
 * cross-origin. Both are checked. Non-browser clients (curl, the CLI) send
 * neither and are allowed through, which keeps the local tool usable.
 *
 * This is the right defence for a loopback tool; setting DASHBOARD_TOKEN adds
 * real authentication on top. Neither replaces the other.
 *
 * Access by a DNS name (e.g. `http://hiyori.local:8080`) is refused by this
 * check; use the machine's IP, `localhost`, or set DASHBOARD_TOKEN.
 */
function sameOriginRequest(): bool
{
    $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site === 'cross-site') {
        return false;
    }

    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return true;
    }

    $host = strtolower(trim((string) (parse_url($origin, PHP_URL_HOST) ?? ''), '[]'));
    if ($host === '') {
        return false;
    }

    // Split the Host header without corrupting an IPv6 literal (`[::1]:8080`).
    $reqHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $reqHost, $m) === 1) {
        $reqName = $m[1];
    } elseif (substr_count($reqHost, ':') === 1) {
        $reqName = (string) strstr($reqHost, ':', true);
    } else {
        $reqName = $reqHost;
    }

    // Origin must match Host *and* Host must be a literal address. Comparing
    // the two alone does not stop DNS rebinding: the attacker's page is served
    // from `evil.com` after that name is re-pointed at 127.0.0.1, so the
    // browser sends `Origin: http://evil.com` and `Host: evil.com` and treats
    // the request as same-origin. A DNS name is therefore refused unless the
    // operator adds DASHBOARD_TOKEN, which is authentication rather than an
    // assumption about where the request came from.
    if ($host !== $reqName) {
        return false;
    }

    return $reqName === 'localhost' || filter_var($reqName, FILTER_VALIDATE_IP) !== false;
}
