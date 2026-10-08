<?php

namespace App\Http\Controllers;

use App\Services\HealthChecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What an uptime monitor can reach.
 *
 * `/up` only proves the application boots. It answers 200 with the database
 * unreachable, the queue worker stopped and the scheduler dead - which is to
 * say it stays green through every failure MealBells actually has. The real
 * signals were behind a login on /super-admin/health, where no monitor can read
 * them and a person has to remember to look.
 *
 * This endpoint is the same signals, machine-readable, behind a shared secret.
 * The checks themselves live in HealthChecks, because the alert mails read the
 * same ones: a green monitor and an inbox full of alerts would leave an
 * operator no way to tell which is lying.
 */
class HealthPingController extends Controller
{
    public function __invoke(Request $request, HealthChecks $checks): JsonResponse
    {
        $this->assertTokenMatches($request);

        $results = $checks->operational();

        $failing = array_keys(array_filter($results, fn (array $check) => $check['ok'] === false));

        return response()->json([
            'status' => $failing === [] ? 'ok' : 'failing',
            'failing' => $failing,
            'checks' => $results,
            'checked_at' => now()->toIso8601String(),
        ], $failing === [] ? 200 : 503);
    }

    /**
     * No token configured means no endpoint.
     *
     * 404 rather than 401 both times, so the response cannot be used to learn
     * that the path exists or that a guessed token was close. hash_equals
     * because a plain === leaks the matching prefix through timing.
     */
    protected function assertTokenMatches(Request $request): void
    {
        $expected = (string) config('health.ping_token', '');

        if ($expected === '') {
            abort(404);
        }

        $given = (string) ($request->header('X-Health-Token') ?? $request->query('token', ''));

        if (! hash_equals($expected, $given)) {
            abort(404);
        }
    }
}
