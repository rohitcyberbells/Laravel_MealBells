<?php

namespace App\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Everything that leaves this application for Sentry passes through here
 * first.
 *
 * An error tracker is a third party that receives, by default, the request body
 * that caused the error. In MealBells that body is sometimes a sign-in form
 * holding a password, sometimes an HRMS payload holding an entire company's
 * leave records with names and employee codes, and sometimes an HRMS credential
 * being saved. None of that may leave the building, and "we turned off
 * send_default_pii" does not cover it: the payload is in the request data, not
 * in the PII fields.
 *
 * So this scrubs by key name rather than by a list of known-bad fields, because
 * the next endpoint somebody adds will not be on a list.
 */
class SentryScrubber
{
    /**
     * Any key whose name matches is replaced wholesale, however deeply nested.
     *
     * 'payload' is here because of the HRMS webhook: one request body holds a
     * company's leave for the day, with employee names and codes.
     */
    protected const SENSITIVE_KEY_PATTERN = '/pass(word)?|secret|token|signature|authorization|cookie|'.
        'api[_-]?key|credential|dsn|payload|email|login_code|employee_code|remember/i';

    /**
     * Request headers dropped outright. Matched case-insensitively.
     */
    protected const SENSITIVE_HEADERS = [
        'authorization', 'cookie', 'set-cookie', 'x-health-token',
        'x-mealbells-signature', 'x-mealbells-timestamp', 'x-csrf-token', 'x-xsrf-token',
    ];

    protected const REDACTED = '[redacted]';

    /**
     * Query-string parameters whose values are stripped from any URL.
     */
    protected const SENSITIVE_QUERY_KEYS = ['token', 'email', 'signature'];

    /**
     * The entry point named in config/sentry.php.
     *
     * Static, and a named method rather than a closure, because config has to
     * survive `config:cache` - a closure there cannot be exported and breaks
     * the cache build on deploy.
     */
    public static function scrub(Event $event, ?EventHint $hint = null): ?Event
    {
        return (new self)->handle($event, $hint);
    }

    public function handle(Event $event, ?EventHint $hint = null): ?Event
    {
        $event->setRequest($this->scrubRequest($event->getRequest()));
        $event->setExtra($this->scrubArray($event->getExtra()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, $this->scrubArray($context));
        }

        $this->scrubUser($event);
        $this->scrubBreadcrumbs($event);
        $this->tag($event);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    protected function scrubRequest(array $request): array
    {
        if (isset($request['headers']) && is_array($request['headers'])) {
            foreach ($request['headers'] as $name => $value) {
                if (in_array(strtolower((string) $name), self::SENSITIVE_HEADERS, true)) {
                    $request['headers'][$name] = self::REDACTED;
                }
            }
        }

        // Never kept, in any form: a session cookie in an error report is a
        // usable credential for as long as the report is readable.
        unset($request['cookies'], $request['env']);

        foreach (['url', 'query_string'] as $key) {
            if (isset($request[$key]) && is_string($request[$key])) {
                $request[$key] = $this->scrubUrl($request[$key]);
            }
        }

        if (isset($request['data'])) {
            $request['data'] = is_array($request['data'])
                ? $this->scrubArray($request['data'])
                // A raw body string cannot be scrubbed key by key, and the one
                // this application receives raw is the HRMS payload.
                : self::REDACTED;
        }

        return $request;
    }

    /**
     * Replaces the value of any sensitive key, at any depth.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->scrubArray($value);

                continue;
            }

            if (is_string($value)) {
                $data[$key] = $this->scrubString($value);
            }
        }

        return $data;
    }

    /**
     * A last pass over free text, for addresses that arrive somewhere nobody
     * anticipated - an exception message, a log line, a breadcrumb.
     *
     * The domain is kept because it is often the useful part of a mail failure,
     * and it identifies nobody on its own.
     */
    protected function scrubString(string $value): string
    {
        return (string) preg_replace(
            '/[\w.+-]+@([\w-]+\.[\w.-]+)/',
            '[email]@$1',
            $value,
        );
    }

    protected function scrubUrl(string $url): string
    {
        foreach (self::SENSITIVE_QUERY_KEYS as $key) {
            $url = (string) preg_replace(
                '/(\b'.preg_quote($key, '/').'=)[^&\s]*/i',
                '$1'.self::REDACTED,
                $url,
            );
        }

        return $this->scrubString($url);
    }

    /**
     * Keeps the id and the role, drops everything that names a person.
     *
     * The id is what makes a report actionable - it says which account hit the
     * error without saying who they are, and anyone who needs the name can look
     * it up in MealBells, where that access is already controlled.
     */
    protected function scrubUser(Event $event): void
    {
        $user = $event->getUser();

        if ($user === null) {
            return;
        }

        $user->setEmail(null);
        $user->setUsername(null);
        $user->setIpAddress(null);

        foreach (array_keys($user->getMetadata()) as $name) {
            if (! in_array($name, ['role', 'company_id'], true)) {
                $user->removeMetadata($name);
            }
        }
    }

    /**
     * Breadcrumbs carry SQL, cache keys and log lines. They are immutable, so a
     * scrubbed one is rebuilt rather than edited.
     */
    protected function scrubBreadcrumbs(Event $event): void
    {
        $event->setBreadcrumb(array_map(
            fn (Breadcrumb $crumb) => new Breadcrumb(
                $crumb->getLevel(),
                $crumb->getType(),
                $crumb->getCategory(),
                $crumb->getMessage() === null ? null : $this->scrubString($crumb->getMessage()),
                $this->scrubArray($crumb->getMetadata()),
                $crumb->getTimestamp(),
            ),
            $event->getBreadcrumbs(),
        ));
    }

    /**
     * Which installation and which build an event came from.
     *
     * Without a release tag every regression looks like it has always been
     * there, and without an environment tag a staging error wakes someone up.
     */
    protected function tag(Event $event): void
    {
        $event->setTag('mealbells.environment', (string) config('app.env'));

        if ($release = config('sentry.release')) {
            $event->setRelease((string) $release);
        }
    }
}
