<?php

namespace App\Providers;

use App\Classes\Synths\PriceSynth;
use App\Helpers\ExtensionHelper;
use App\Models\EmailLog;
use App\Models\Extension;
use App\Models\OauthClient;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use League\CommonMark\Extension\Table\TableExtension;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Service provider for settings
        $this->app->register(SettingsProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Change livewire url
        \Livewire\Livewire::setUpdateRoute(function ($handle) {
            return \Illuminate\Support\Facades\Route::post('/paymenter/update', $handle)->middleware('web')->name('paymenter.');
        });
        \Livewire\Livewire::propertySynthesizer(PriceSynth::class);

        Gate::define('has-permission', function (User $user, string $ability) {
            return $user->hasPermission($ability);
        });

        Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
            $event->extendSocialite('discord', \SocialiteProviders\Discord\Provider::class);
        });

        try {
            foreach (
                collect(Extension::where(function ($query) {
                    $query->where('enabled', true)->orWhere('type', 'server')->orWhere('type', 'gateway');
                })->get())->unique('extension') as $extension
            ) {
                ExtensionHelper::call($extension, 'boot', mayFail: true);
            }
        } catch (\Exception $e) {
            // Fail silently
        }

        Queue::after(function (JobProcessed $event) {
            if ($event->job->resolveName() === 'App\Mail\Mail') {
                $payload = json_decode($event->job->getRawBody());
                $data = unserialize($payload->data->command);
                EmailLog::where('id', $data->mailable->email_log_id)->update([
                    'sent_at' => now(),
                    'status' => 'sent',
                ]);
            }
        });

        Queue::failing(function (JobFailed $event) {
            if ($event->job->resolveName() === 'App\Mail\Mail') {
                $payload = json_decode($event->job->getRawBody());
                $data = unserialize($payload->data->command);
                EmailLog::where('id', $data->mailable->email_log_id)->update([
                    'status' => 'failed',
                    'error' => $event->exception->getMessage(),
                    'job_uuid' => $event->job->uuid(),
                ]);
            }
        });

        Str::macro('markdown', function ($markdown) {
            return Str::markdown($markdown, extensions: [
                new TableExtension,
            ]);
        });

        Passport::clientModel(OauthClient::class);
        Passport::ignoreRoutes();
        Passport::tokensCan([
            'profile' => 'View your profile',
        ]);

        if (class_exists(Scramble::class)) {
            Scramble::configure()
                ->routes(function (\Illuminate\Routing\Route $route) {
                    return Str::startsWith($route->uri, 'api/v1/admin');
                })
                ->withDocumentTransformers(function (OpenApi $openApi) {
                    $openApi->secure(
                        SecurityScheme::http('bearer')
                    );
                });
        }

        // === Begin Plugin Scan, Load & Global Blade Vars Injection ===

        // Initialize global blade vars container if not exists
        $GLOBALS['plugin_blade_vars'] = $GLOBALS['plugin_blade_vars'] ?? [];

        $pluginDir = '/var/www/paymenter/plugins';
        $loadedPlugins = [];
        $startTime = microtime(true);

        if (is_dir($pluginDir)) {
            foreach (scandir($pluginDir) as $pluginFolder) {
                if ($pluginFolder === '.' || $pluginFolder === '..') {
                    continue;
                }

                $pluginPath = $pluginDir . DIRECTORY_SEPARATOR . $pluginFolder;
                if (!is_dir($pluginPath)) {
                    continue;
                }

                // Assumed namespace: Plugins\<PluginFolder>\
                // And ServiceProvider class name: <PluginFolder>ServiceProvider
                // e.g. Plugins\TestPlugin\TestPluginServiceProvider

                $providerClass = "Plugins\\$pluginFolder\\{$pluginFolder}ServiceProvider";

                if (!class_exists($providerClass)) {
                    // Try to include the provider file manually if not autoloaded
                    $providerFile = $pluginPath . DIRECTORY_SEPARATOR . "{$pluginFolder}ServiceProvider.php";
                    if (file_exists($providerFile)) {
                        require_once $providerFile;
                    }
                }

                if (class_exists($providerClass)) {
                    // Instantiate and boot the plugin provider
                    $provider = new $providerClass($this->app);

                    // Call boot() if exists (to load routes, etc.)
                    if (method_exists($provider, 'boot')) {
                        $provider->boot();
                    }

                    // Collect blade vars if method exists
                    if (method_exists($provider, 'registerBladeVars')) {
                        $vars = $provider->registerBladeVars();

                        if (is_array($vars)) {
                            // Merge plugin vars into global blade vars
                            $GLOBALS['plugin_blade_vars'] = array_merge($GLOBALS['plugin_blade_vars'], $vars);
                        }
                    }

                    $loadedPlugins[] = $pluginFolder;
                }
            }
        }

        $ping = microtime(true) - $startTime;

        // Add your standard global plugin blade vars
        $GLOBALS['plugin_blade_vars'] = array_merge($GLOBALS['plugin_blade_vars'], [
            'PTB_VERSION'          => $this->app->version(), // or your own version string
            'PTB_PLUGIN_COUNT'     => count($loadedPlugins),
            'PTB_LOAD_TIME'        => round($ping, 4),  // seconds to load plugins
            'PTB_SERVER_TIME'      => date('H:i:s'),
            'PTB_PING'             => round($ping, 4),
            'PTB_APP_NAME'         => config('app.name', 'Laravel'),
            'PTB_LARAVEL_VERSION'  => app()->version(),
            'PTB_PHP_VERSION'      => PHP_VERSION,
            'PTB_MEMORY_USAGE_MB'  => round(memory_get_usage(true) / 1024 / 1024, 2),
            'PTB_REQUEST_URL'      => request()->fullUrl(),
            'PTB_REQUEST_METHOD'   => request()->method(),
            'PTB_USER_ID'          => auth()->id() ?? null,
        ]);

        // Inject all plugin blade vars into every view globally
        View::composer('*', function ($view) {
            if (!empty($GLOBALS['plugin_blade_vars'])) {
                $view->with($GLOBALS['plugin_blade_vars']);
            }
        });

        // === End Plugin logic ===
    }
}
