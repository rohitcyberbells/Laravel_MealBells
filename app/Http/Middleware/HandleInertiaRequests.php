<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user?->only(['id', 'name', 'email', 'role']),
            ],
            // Kept as its own prop because pages already read it; the layout uses
            // workspace for the subtitle so a vendor or the platform reads well
            // too.
            'company' => $this->company($request),
            'workspace' => $this->workspace($request),
            // The sidebar renders exactly this, so a role can never be handed
            // another role's links.
            'navigation' => $user ? config("navigation.items.{$user->role}", []) : [],
            'flash' => [
                'message' => $request->session()->get('message'),
                'error' => $request->session()->get('error'),
                // The employee CSV modal reads flash.csvPreview. Without it here
                // the preview was computed, flashed, and never reached the page,
                // so the modal could not leave its first step.
                'csvPreview' => $request->session()->get('csvPreview'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function company(Request $request): ?array
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        $company = $user->company ?? $user->employee?->company;

        return $company?->only(['id', 'name', 'code']);
    }

    protected function workspace(Request $request): ?string
    {
        $user = $request->user();

        return match ($user?->role) {
            'super_admin' => 'Platform',
            'tiffin_admin' => $user->tiffinService?->name,
            'company_admin' => $user->company?->name,
            'employee' => $user->employee?->company?->name,
            default => null,
        };
    }
}
