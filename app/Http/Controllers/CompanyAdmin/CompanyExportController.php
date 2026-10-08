<?php

namespace App\Http\Controllers\CompanyAdmin;

use App\Actions\Company\BuildCompanyExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CompanyExportController extends Controller
{
    /**
     * Download everything this company holds, as CSV inside a zip.
     *
     * The company comes from the signed-in admin and is never accepted from the
     * request: an export is the one response that hands over a whole tenant's
     * personal data in one file, so there is no parameter to tamper with.
     */
    public function download(Request $request, BuildCompanyExport $action): BinaryFileResponse
    {
        $user = Auth::user();
        $company = $user->company;

        if (! $company) {
            abort(404, 'Company not found.');
        }

        $path = $action->execute($company, $user->email);

        // deleteFileAfterSend, so a file holding every employee's name and
        // address does not accumulate in storage after being handed over.
        return response()->download($path, basename($path), [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
