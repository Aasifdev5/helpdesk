<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RedirectIfNotParmittedMultiple;
use App\Models\Language;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware(RedirectIfNotParmittedMultiple::class.':global,smtp,pusher');
    }

    const GITHUB_API_URL = 'https://api.github.com';
    const GITHUB_REPO = 'Aasifdev5/helpdesk';

    public function systemUpdate()
    {
        $current_version = env('VERSION', '1.0.0');
        $demo = config('app.demo');
        return Inertia::render('Settings/Update', [
            'title' => 'System Update',
            'current_version' => $current_version,
            'demo' => boolval($demo),
        ]);
    }

    public function systemUpdateCheck()
    {
        $current_version = env('VERSION', '1.0.0');
        $new_tag = $this->getVersionAvailable($current_version);
        $diffs = [];
        if ($new_tag) {
            $headers = [
                'Authorization' => 'Bearer ' . env('GITHUB_TOKEN'),
                'Accept' => 'application/vnd.github.v3+json',
            ];
            $res = Http::withHeaders($headers)->get(self::GITHUB_API_URL . '/repos/' . self::GITHUB_REPO . '/compare/' . $current_version . '...' . $new_tag);
            $json = $res->json();
            $diffs = $json['files'] ?? [];
        }
        return response()->json(['files' => $diffs, 'version' => $new_tag]);
    }

    protected function getVersionAvailable($current)
    {
        $headers = [
            'Authorization' => 'Bearer ' . env('GITHUB_TOKEN'),
            'Accept' => 'application/vnd.github.v3+json',
        ];
        $response = Http::withHeaders($headers)->get(self::GITHUB_API_URL . '/repos/' . self::GITHUB_REPO . '/releases/latest');
        $release = $response->json();
        if (!empty($release['tag_name'])) {
            if (version_compare($current, $release['tag_name'], '<')) {
                return $release['tag_name'];
            }
        }
        return false;
    }

    private function configExist(array $keys)
    {
        foreach ($keys as $key) {
            if (empty(env($key))) {
                return false;
            }
        }
        return true;
    }

    public function index()
    {
        $pusher_setup = $this->configExist(['PUSHER_APP_ID', 'PUSHER_APP_KEY', 'PUSHER_APP_SECRET']);
        $piping_setup = $this->configExist(['IMAP_HOST', 'IMAP_PORT', 'IMAP_PROTOCOL', 'IMAP_ENCRYPTION', 'IMAP_USERNAME', 'IMAP_PASSWORD']);
        $settings = Setting::orderBy('id')->get();
        $setting_data = [];
        foreach ($settings as $setting) {
            $setting_data[$setting['slug']] = [
                'id' => $setting->id,
                'name' => $setting->name,
                'slug' => $setting->slug,
                'type' => $setting->type,
                'value' => $setting->type === 'json' ? ($setting->value ? json_decode($setting->value, true) : null) : $setting->value,
            ];
        }
        $custom_css = File::get(public_path('css/custom.css'));
        $setting_data['custom_css'] = ['slug' => 'custom_css', 'name' => 'Custom CSS', 'value' => $custom_css];
        $site_key = env('RECAPTCHA_SECRET_KEY', '');
        return Inertia::render('settings/index', [
            'title' => 'Global Settings',
            'site_key' => $site_key,
            'settings' => $setting_data,
            'pusher' => $pusher_setup,
            'piping' => $piping_setup,
            'languages' => Language::orderBy('name')->get(['code', 'name']),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update()
    {
        $requests = Request::all();

        if (config('app.demo')) {
            return Redirect::back()->with('error', 'Updating global settings is not allowed in demo mode.');
        }

        if (!empty($requests['custom_css'])) {
            Storage::disk('public_path')->put('css/custom.css', $requests['custom_css']);
        }

        if (!empty($requests['site_key'])) {
            $this->updateEnv('RECAPTCHA_SECRET_KEY', $requests['site_key']);
        }

        unset($requests['custom_css']);
        $json_fields = ['hide_ticket_fields', 'required_ticket_fields'];

        foreach ($requests as $key => $value) {
            $setting = Setting::where('slug', $key)->first();
            if ($setting) {
                $setting->value = in_array($setting->slug, $json_fields) ? json_encode($value) : $value;
                $setting->save();
            } else {
                Setting::create([
                    'slug' => $key,
                    'name' => ucfirst(str_replace('_', ' ', $key)),
                    'type' => in_array($key, $json_fields) ? 'json' : 'text',
                    'value' => in_array($key, $json_fields) ? json_encode($value) : $value,
                ]);
            }
        }

        if ($logo = Request::file('logo')) {
            $logo->storeAs('/', 'logo.png', ['disk' => 'image']);
            Artisan::call('cache:clear');
        }

        if ($logo_white = Request::file('logo_white')) {
            $logo_white->storeAs('/', 'logo_white.png', ['disk' => 'image']);
            Artisan::call('cache:clear');
        }

        if ($favicon = Request::file('favicon')) {
            $favicon->storeAs('/', 'favicon.png', ['disk' => 'public_path']);
            Artisan::call('cache:clear');
        }

        if (!empty($requests['default_language'])) {
            User::whereNotNull('locale')->update(['locale' => $requests['default_language']]);
        }

        return Redirect::back()->with('success', 'Settings updated.');
    }

    public function smtp()
    {
        $demo = config('app.demo');
        $keys = [
            'MAIL_HOST' => ['value' => env('MAIL_HOST')],
            'MAIL_PORT' => ['value' => env('MAIL_PORT')],
            'MAIL_USERNAME' => ['value' => env('MAIL_USERNAME')],
            'MAIL_PASSWORD' => ['value' => env('MAIL_PASSWORD')],
            'MAIL_ENCRYPTION' => ['value' => env('MAIL_ENCRYPTION')],
            'MAIL_FROM_ADDRESS' => ['value' => env('MAIL_FROM_ADDRESS')],
            'MAIL_FROM_NAME' => ['value' => env('MAIL_FROM_NAME')],
        ];
        return Inertia::render('Settings/Smtp', [
            'title' => 'SMTP Settings',
            'keys' => $keys,
            'demo' => boolval($demo),
        ]);
    }

    public function updateSmtp()
    {
        if (config('app.demo')) {
            return Redirect::back()->with('error', 'Updating SMTP settings is not allowed in demo mode.');
        }

        $mail_variables = Request::validate([
            'MAIL_HOST' => ['required'],
            'MAIL_PORT' => ['required'],
            'MAIL_USERNAME' => ['required'],
            'MAIL_PASSWORD' => ['required'],
            'MAIL_ENCRYPTION' => ['required'],
            'MAIL_FROM_ADDRESS' => ['nullable', 'email'],
            'MAIL_FROM_NAME' => ['nullable'],
        ]);

        $this->updateEnvMultiple($mail_variables);
        return Redirect::back()->with('success', 'SMTP configuration updated!');
    }

    public function pusher()
    {
        $demo = config('app.demo');
        $keys = [
            'PUSHER_APP_ID' => ['value' => $demo ? '*******' : env('PUSHER_APP_ID')],
            'PUSHER_APP_KEY' => ['value' => $demo ? '*********************' : env('PUSHER_APP_KEY')],
            'PUSHER_APP_SECRET' => ['value' => $demo ? '********************' : env('PUSHER_APP_SECRET')],
            'PUSHER_APP_CLUSTER' => ['value' => env('PUSHER_APP_CLUSTER')],
        ];
        return Inertia::render('Settings/Pusher', [
            'title' => 'Pusher Settings',
            'keys' => $keys,
        ]);
    }

    public function updatePusher()
    {
        if (config('app.demo')) {
            return Redirect::back()->with('error', 'Updating Pusher settings is not allowed in demo mode.');
        }

        $pusher_variables = Request::validate([
            'PUSHER_APP_ID' => ['required'],
            'PUSHER_APP_KEY' => ['required'],
            'PUSHER_APP_SECRET' => ['required'],
            'PUSHER_APP_CLUSTER' => ['required'],
        ]);

        $this->updateEnvMultiple($pusher_variables);
        $this->updateJsPusherConfig();
        return Redirect::back()->with('success', 'Pusher configuration updated!');
    }

    private function updateJsPusherConfig()
    {
        $js_file = File::get(public_path('js/app.js'));
        $line_position = strrpos($js_file, 'broadcaster:"pusher",key:');
        if ($line_position !== false) {
            $pusher_key = substr($js_file, $line_position + 26, 20);
            $cluster = substr($js_file, $line_position + 57, 3);
            $new_key = env('PUSHER_APP_KEY');
            $new_cluster = env('PUSHER_APP_CLUSTER');

            if (strlen($pusher_key) === 20 && $new_key) {
                $js_file = str_replace($pusher_key, $new_key, $js_file);
            }

            if (strlen($cluster) === 3 && $new_cluster) {
                $js_file = str_replace($cluster, $new_cluster, $js_file);
            }

            File::delete(public_path('js/app.js'));
            Storage::disk('public_path')->put('js/app.js', $js_file);
        }
    }

    private function updateEnv($key, $value)
    {
        $path = base_path('.env');
        $content = File::get($path);
        $pattern = "/^{$key}=.*$/m";
        $replacement = "{$key}=" . str_replace("\n", '\\n', $value);

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content);
        } else {
            $content .= "\n{$replacement}";
        }

        File::put($path, $content);
    }

    private function updateEnvMultiple(array $data)
    {
        foreach ($data as $key => $value) {
            $this->updateEnv($key, $value);
        }
    }

    public function piping()
    {
        $demo = config('app.demo');
        $keys = [
            'IMAP_HOST' => ['value' => $demo ? '*********************' : env('IMAP_HOST')],
            'IMAP_PORT' => ['value' => $demo ? '*********************' : env('IMAP_PORT')],
            'IMAP_PROTOCOL' => ['value' => $demo ? '********************' : env('IMAP_PROTOCOL')],
            'IMAP_ENCRYPTION' => ['value' => $demo ? '********************' : env('IMAP_ENCRYPTION')],
            'IMAP_USERNAME' => ['value' => $demo ? '********************' : env('IMAP_USERNAME')],
            'IMAP_PASSWORD' => ['value' => $demo ? '********************' : env('IMAP_PASSWORD')],
        ];
        $setting = Setting::where('slug', 'enable_options')->first();
        $options = $setting && $setting->value ? json_decode($setting->value, true) : [];
        $key = array_search('enable_piping', array_column($options, 'slug'));
        $option = $options[$key] ?? null;

        return Inertia::render('Settings/Piping', [
            'title' => 'Email Piping Settings',
            'keys' => $keys,
            'option' => $option,
            'demo' => boolval($demo),
        ]);
    }

    public function updatePiping()
    {
        if (config('app.demo')) {
            return Redirect::back()->with('error', 'Updating piping settings is not allowed in demo mode.');
        }

        if ($enable_piping = Request::input('enable_piping')) {
            $setting = Setting::where('slug', 'enable_options')->first();
            $options = $setting && $setting->value ? json_decode($setting->value, true) : [];
            $key = array_search('enable_piping', array_column($options, 'slug'));
            if ($key !== false) {
                $options[$key]['value'] = $enable_piping['value'];
                $setting->value = json_encode($options);
                $setting->save();
            }
        }

        $piping_variables = Request::validate([
            'IMAP_HOST' => ['required'],
            'IMAP_PORT' => ['required'],
            'IMAP_PROTOCOL' => ['required'],
            'IMAP_ENCRYPTION' => ['required'],
            'IMAP_USERNAME' => ['required'],
            'IMAP_PASSWORD' => ['required'],
        ]);

        $this->updateEnvMultiple($piping_variables);
        return Redirect::back()->with('success', 'Piping settings updated!');
    }

    public function clearCache($slug)
    {
        $commands = [
            'config' => 'config:cache',
            'optimize' => 'optimize',
            'cache' => 'cache:clear',
            'route' => 'route:cache',
            'view' => 'view:clear',
        ];

        if (isset($commands[$slug])) {
            Artisan::call($commands[$slug]);
        } elseif ($slug === 'all') {
            Artisan::call('optimize');
            Artisan::call('cache:clear');
            Artisan::call('route:cache');
            Artisan::call('view:clear');
            Artisan::call('config:cache');
            Artisan::call('clear-compiled');
        }

        return response()->json(['success' => true]);
    }
}
