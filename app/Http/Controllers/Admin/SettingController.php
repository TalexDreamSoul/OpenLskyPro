<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConfigKey;
use App\Http\Controllers\Controller;
use App\Mail\Test;
use App\Services\UpgradeService;
use App\Utils;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function index(): View
    {
        $configs = Utils::config();
        return view('admin.setting.index', compact('configs'));
    }

    public function save(Request $request): Response
    {
        foreach ($request->all() as $key => $value) {
            if ($key === ConfigKey::Casdoor && is_array($value)) {
                $value = $this->normalizeCasdoorConfig($value);
            }

            DB::table('configs')->updateOrInsert(
                ['name' => $key],
                [
                    'value' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
        Cache::forget('configs');
        Cache::flush();
        return $this->success('保存成功');
    }

    private function normalizeCasdoorConfig(array $config): array
    {
        $current = json_decode((string) DB::table('configs')->where('name', ConfigKey::Casdoor)->value('value'), true) ?: [];
        $defaults = config('convention.app.'.ConfigKey::Casdoor, []);
        $config = array_merge($defaults, $current, $config);

        $config['enabled'] = filter_var($config['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $config['issuer'] = rtrim((string) ($config['issuer'] ?? ''), '/');
        $config['client_id'] = (string) ($config['client_id'] ?? '');
        $config['redirect'] = (string) ($config['redirect'] ?? '');
        $config['scope'] = trim((string) ($config['scope'] ?? '')) ?: 'openid profile email';
        $config['pending_ttl'] = max(1, (int) ($config['pending_ttl'] ?? 10));

        if (($config['client_secret'] ?? '') === '') {
            $config['client_secret'] = $current['client_secret'] ?? '';
        } else {
            $config['client_secret'] = (string) $config['client_secret'];
        }

        return $config;
    }

    public function mailTest(Request $request): Response
    {
        try {
            Mail::to($request->post('email'))->send(new Test());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success('发送成功');
    }

    public function checkUpdate(): Response
    {
        $version = Utils::config(ConfigKey::AppVersion);
        $service = new UpgradeService($version);
        try {
            $data = [
                'is_update' => $service->check(),
            ];
            if ($data['is_update']) {
                $data['version'] = $service->getVersions()->first();
            }
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }

        return $this->success('success', $data);
    }

    public function upgrade()
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $version = Utils::config(ConfigKey::AppVersion);
        $service = new UpgradeService($version);
        $this->success()->send();
        $service->upgrade();
        flush();
    }

    public function upgradeProgress(): Response
    {
        return $this->success('success', Cache::get('upgrade_progress'));
    }
}
