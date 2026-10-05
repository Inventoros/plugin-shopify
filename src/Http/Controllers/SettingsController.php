<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Starter\Http\Controllers;

use App\Models\User;
use App\Services\PluginUIService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inventoros\Plugins\Starter\Jobs\RecordActivity;
use Inventoros\Plugins\Starter\Models\Activity;
use Inventoros\Plugins\Starter\Support\Permissions;
use Inventoros\Plugins\Starter\Support\Settings;

final class SettingsController extends Controller
{
    /**
     * Props of the settings page (register_page calls this per request, only
     * for users who passed the page's permission check).
     *
     * @return array<string, mixed>
     */
    public static function pageProps(User $user): array
    {
        $organizationId = (int) $user->organization_id;

        return [
            'settings' => Settings::for($organizationId)->all(),
            'activity' => Activity::query()
                ->forOrganization($organizationId)
                ->latest('id')
                ->limit(10)
                ->get(['id', 'type', 'message', 'created_at'])
                ->map(fn (Activity $line) => [
                    'id' => $line->id,
                    'type' => $line->type,
                    'message' => $line->message,
                    'created_at' => $line->created_at?->toIso8601String(),
                ])
                ->all(),
            'updateUrl' => route('starter.settings.update'),
        ];
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless(PluginUIService::allows($user, Permissions::manage()), 403);

        $validated = $request->validate([
            'greeting' => ['required', 'string', 'max:120'],
            'log_low_stock' => ['required', 'boolean'],
        ]);

        $organizationId = (int) $user->organization_id;

        Settings::for($organizationId)->put([
            'greeting' => $validated['greeting'],
            'log_low_stock' => (bool) $validated['log_low_stock'],
        ]);

        RecordActivity::dispatch($organizationId, 'settings', 'Settings updated by '.$user->name);

        return back()->with('success', __('starter::messages.saved'));
    }
}
