<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Skip\ImportSkips;
use App\Actions\Skip\ValidateSkipCsv;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SkipImportController extends Controller
{
    public function preview(Request $request, ValidateSkipCsv $action)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
            'type' => 'required|in:leave,wfh',
        ]);

        $user = Auth::user();
        $company = $user->company;

        if (! $company) {
            return response()->json(['message' => 'User does not belong to a company.'], 403);
        }

        $result = $action->execute($request->file('file'), $company, $request->input('type'));

        $token = Str::random(40);
        $cacheKey = "skip_import:{$company->id}:{$token}";

        Cache::put($cacheKey, [
            'valid_rows' => $result['valid_rows'],
            'summary' => $result['summary'],
        ], now()->addMinutes(30));

        return response()->json([
            'summary' => $result['summary'],
            'errors' => $result['errors'],
            'token' => $token,
        ]);
    }

    public function confirm(Request $request, ImportSkips $action)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $user = Auth::user();
        $company = $user->company;

        if (! $company) {
            return response()->json(['message' => 'User does not belong to a company.'], 403);
        }

        $token = $request->input('token');
        $cacheKey = "skip_import:{$company->id}:{$token}";

        if (! Cache::has($cacheKey)) {
            return response()->json(['message' => 'Import token not found or expired.'], 404);
        }

        $data = Cache::pull($cacheKey);
        $outcomes = $action->execute($company, $data['valid_rows'] ?? [], $user);

        return response()->json([
            'outcomes' => $outcomes,
            'message' => 'Skip import processed successfully.',
        ]);
    }
}
