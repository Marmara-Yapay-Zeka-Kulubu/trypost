<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Http;

use App\Http\Controllers\Controller;
use App\Marmara\Designs\DesignsConfig;
use App\Marmara\Designs\Drive\DriveException;
use App\Marmara\Designs\Models\DesignDriveConnection;
use App\Marmara\Designs\Upload\DesignUploadUnavailable;
use App\Marmara\Designs\Upload\DriveUploadAuth;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Drive'a yükleme izni": a workspace admin (the club lead) connects their
 * own Google account with drive.file, then picks the shared folder once in
 * the Google Picker so the token may write into it.
 */
class DriveConnectController extends Controller
{
    use ResolvesDesigns;

    private const string SESSION_KEY = 'marmara-designs.oauth';

    public function __construct(private readonly DesignsConfig $config, private readonly DriveUploadAuth $auth) {}

    public function connect(Request $request): RedirectResponse
    {
        $workspace = $this->adminWorkspace($request);

        if (! $this->config->oauthConfigured()) {
            return redirect()->route('marmara.designs.index')->with('flash.banner', __('marmara-designs::designs.drive.not_configured'))->with('flash.bannerStyle', 'danger');
        }

        $state = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'verifier' => $verifier, 'workspace_id' => $workspace->id]);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return redirect()->away($this->auth->authorizeUrl($state, $challenge));
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->adminWorkspace($request);
        $pending = (array) $request->session()->pull(self::SESSION_KEY, []);
        $index = redirect()->route('marmara.designs.index');

        if (($pending['state'] ?? null) === null || ! hash_equals((string) $pending['state'], (string) $request->query('state')) || ($pending['workspace_id'] ?? null) !== $workspace->id) {
            abort(403);
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return $index->with('flash.banner', __('marmara-designs::designs.drive.failed', ['detail' => (string) $request->query('error', 'no code')]))->with('flash.bannerStyle', 'danger');
        }

        try {
            $this->auth->connect($workspace, $request->user(), (string) $request->query('code'), (string) $pending['verifier']);
        } catch (DriveException $exception) {
            $message = $exception->reason === 'no_refresh_token'
                ? __('marmara-designs::designs.drive.no_refresh_token')
                : __('marmara-designs::designs.drive.failed', ['detail' => $exception->getMessage()]);

            return $index->with('flash.banner', $message)->with('flash.bannerStyle', 'danger');
        }

        return redirect()->route('marmara.designs.index', ['drive' => 'pick'])->with('flash.banner', __('marmara-designs::designs.drive.connected'));
    }

    /**
     * A short-lived drive.file token for the Google Picker in the admin's
     * browser (the same as upstream's Drive media source does).
     */
    public function pickerToken(Request $request): JsonResponse
    {
        $connection = $this->connection($this->adminWorkspace($request));

        try {
            $token = $this->auth->accessToken($connection);
        } catch (DesignUploadUnavailable|DriveException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        $picker = $this->config->picker();

        return response()->json([
            'access_token' => $token,
            'api_key' => $picker['api_key'],
            'app_id' => $picker['app_id'],
            'sdk' => $picker['sdk'],
            'folder_id' => $this->config->folderId(),
        ])->header('Cache-Control', 'no-store');
    }

    public function grantFolder(Request $request): RedirectResponse
    {
        $connection = $this->connection($this->adminWorkspace($request));
        $validated = $request->validate(['folder_id' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]+$/']]);

        if ($validated['folder_id'] !== $this->config->folderId()) {
            throw ValidationException::withMessages(['folder_id' => __('marmara-designs::designs.drive.wrong_folder')]);
        }

        if (! $this->auth->grantFolder($connection, $validated['folder_id'])) {
            throw ValidationException::withMessages(['folder_id' => __('marmara-designs::designs.drive.folder_failed')]);
        }

        return back();
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $connection = DesignDriveConnection::query()->where('workspace_id', $this->adminWorkspace($request)->id)->first();

        if ($connection !== null) {
            $this->auth->disconnect($connection);
        }

        return back()->with('flash.banner', __('marmara-designs::designs.drive.disconnected'));
    }

    private function adminWorkspace(Request $request): Workspace
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        return $workspace;
    }

    private function connection(Workspace $workspace): DesignDriveConnection
    {
        $connection = DesignDriveConnection::query()->where('workspace_id', $workspace->id)->first();
        abort_if($connection === null, 404);

        return $connection;
    }
}
