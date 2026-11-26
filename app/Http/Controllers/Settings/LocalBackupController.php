<?php

namespace App\Http\Controllers\Settings;

use App\Actions\CreateLocalBackup;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class LocalBackupController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/Backups', [
            'backupError' => $request->session()->get('backupError'),
        ]);
    }

    public function download(CreateLocalBackup $backup): BinaryFileResponse|RedirectResponse
    {
        $archive = null;

        try {
            $archive = $backup->handle();

            return response()->download($archive, 'hostal-rincon-'.now()->format('Y-m-d-His').'.zip', [
                'Cache-Control' => 'private, no-store',
                'Content-Type' => 'application/zip',
            ])->setPrivate()->deleteFileAfterSend();
        } catch (Throwable $exception) {
            if ($archive !== null) {
                File::delete($archive);
            }

            report($exception);

            return to_route('backups.index')->with(
                'backupError',
                'No pudimos generar el respaldo. Verifica que haya espacio disponible y vuelve a intentarlo.',
            );
        }
    }
}
