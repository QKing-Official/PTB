<?php

namespace App\Providers;

use App\Classes\Synths\PriceSynth;
use App\Helpers\ExtensionHelper;
use App\Models\EmailLog;
use App\Models\Extension;
use App\Models\OauthClient;
use App\Models\User;
use App\Support\Passport\ScopeRegistry;
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

        // Speed optimization: Cache extensions query and use single try-catch
        try {
            $extensions = Extension::where(function ($query) {
                $query->where('enabled', true)->orWhere('type', 'server')->orWhere('type', 'gateway');
            })
            ->select(['extension', 'id']) // Only select needed columns
            ->get()
            ->unique('extension');

            foreach ($extensions as $extension) {
                ExtensionHelper::call($extension, 'boot', mayFail: true);
            }
        } catch (\Exception $e) {
            // Fail silently
        }

        // Speed optimization: Use optimized queue listeners
        $this->registerQueueListeners();

        // Speed optimization: Register macro once
        Str::macro('markdown', function ($markdown) {
            return Str::markdown($markdown, extensions: [
                new TableExtension,
            ]);
        });

        // Speed optimization: Configure Passport in one place
        Passport::clientModel(OauthClient::class);
        Passport::ignoreRoutes();
        Passport::tokensCan(ScopeRegistry::getAll());

        // Speed optimization: Configure Scramble if available
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

        // === Begin Plugin Scan, Load & Global Blade Vars Injection with Speed Optimizations ===
        $this->loadPluginsWithOptimizations();
        // === End Plugin logic ===
    }

    /**
     * Register queue event listeners with optimized handling
     */
    protected function registerQueueListeners(): void
    {
        Queue::after(function (JobProcessed $event) {
            if ($event->job->resolveName() === 'App\Mail\Mail') {
                $this->handleEmailJobSuccess($event);
            }
        });

        Queue::failing(function (JobFailed $event) {
            if ($event->job->resolveName() === 'App\Mail\Mail') {
                $this->handleEmailJobFailure($event);
            }
        });
    }

    /**
     * Handle successful email job processing with error handling
     */
    protected function handleEmailJobSuccess(JobProcessed $event): void
    {
        try {
            $payload = json_decode($event->job->getRawBody(), true);
            if (isset($payload['data']['command'])) {
                $data = unserialize($payload['data']['command']);
                if (isset($data->mailable->email_log_id)) {
                    EmailLog::where('id', $data->mailable->email_log_id)->update([
                        'sent_at' => now(),
                        'status' => 'sent',
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Log error but don't throw to avoid breaking the queue
            \Log::warning('Failed to update email log on job success', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Handle failed email job processing with error handling
     */
    protected function handleEmailJobFailure(JobFailed $event): void
    {
        try {
            $payload = json_decode($event->job->getRawBody(), true);
            if (isset($payload['data']['command'])) {
                $data = unserialize($payload['data']['command']);
                if (isset($data->mailable->email_log_id)) {
                    EmailLog::where('id', $data->mailable->email_log_id)->update([
                        'status' => 'failed',
                        'error' => $event->exception->getMessage(),
                        'job_uuid' => $event->job->uuid(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Log error but don't throw to avoid breaking the queue
            \Log::warning('Failed to update email log on job failure', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Load plugins with speed optimizations
     */
    protected function loadPluginsWithOptimizations(): void
    {
        // Initialize global blade vars container if not exists
        $GLOBALS['plugin_blade_vars'] = $GLOBALS['plugin_blade_vars'] ?? [];

        $pluginDir = '/var/www/paymenter/plugins';
        $loadedPlugins = [];
        $startTime = microtime(true);

        // Speed optimization: Check if plugin directory exists before processing
        if (!is_dir($pluginDir)) {
            return;
        }

        // Speed optimization: Use optimized directory scanning
        $pluginFolders = array_diff(scandir($pluginDir), ['.', '..']);
        
        foreach ($pluginFolders as $pluginFolder) {
            $pluginPath = $pluginDir . DIRECTORY_SEPARATOR . $pluginFolder;
            
            // Speed optimization: Skip non-directories early
            if (!is_dir($pluginPath)) {
                continue;
            }

            $this->loadSinglePlugin($pluginFolder, $pluginPath, $loadedPlugins);
        }

        $loadTime = microtime(true) - $startTime;

        // Speed optimization: Pre-calculate common values
        $this->setupGlobalBladeVars($loadedPlugins, $loadTime);

        // Speed optimization: Register view composer once with all vars
        View::composer('*', function ($view) {
            if (!empty($GLOBALS['plugin_blade_vars'])) {
                $view->with($GLOBALS['plugin_blade_vars']);
            }
        });
    }

    /**
     * Load a single plugin with error handling
     */
    protected function loadSinglePlugin(string $pluginFolder, string $pluginPath, array &$loadedPlugins): void
    {
        try {
            // Assumed namespace: Plugins\<PluginFolder>\
            // And ServiceProvider class name: <PluginFolder>ServiceProvider
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
                        // Speed optimization: Use array_merge only when necessary
                        $GLOBALS['plugin_blade_vars'] = array_merge($GLOBALS['plugin_blade_vars'], $vars);
                    }
                }

                $loadedPlugins[] = $pluginFolder;
            }
        } catch (\Exception $e) {
            // Log plugin loading errors but continue with other plugins
            \Log::warning("Failed to load plugin: $pluginFolder", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Setup global blade variables with optimized calculations
     */
    protected function setupGlobalBladeVars(array $loadedPlugins, float $loadTime): void
    {
        // Speed optimization: Calculate values once and cache request data
        static $requestData = null;
        if ($requestData === null) {
            $request = request();
            $requestData = [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ];
        }

        $standardVars = [
            'PTB_VERSION'          => $this->app->version(),
            'PTB_PLUGIN_COUNT'     => count($loadedPlugins),
            'PTB_LOAD_TIME'        => round($loadTime, 4),
            'PTB_SERVER_TIME'      => date('H:i:s'),
            'PTB_PING'             => round($loadTime, 4),
            'PTB_APP_NAME'         => config('app.name', 'Laravel'),
            'PTB_LARAVEL_VERSION'  => app()->version(),
            'PTB_PHP_VERSION'      => PHP_VERSION,
            'PTB_MEMORY_USAGE_MB'  => round(memory_get_usage(true) / 1024 / 1024, 2),
            'PTB_REQUEST_URL'      => $requestData['url'],
            'PTB_REQUEST_METHOD'   => $requestData['method'],
            'PTB_USER_ID'          => auth()->id() ?? null,
        ];

        // Speed optimization: Single array_merge operation
        $GLOBALS['plugin_blade_vars'] = array_merge($GLOBALS['plugin_blade_vars'], $standardVars);
    }
}
