<?php

namespace App\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if (in_array($event->command, ['migrate:fresh', 'migrate:reset', 'migrate:refresh', 'db:wipe'], true)) {
                self::cleanPublicStorage();
            }
        });
    }

    /**
     * Clean all files and directories in public storage except hidden dotfiles (.gitignore, etc.)
     */
    public static function cleanPublicStorage(): void
    {
        $disk = Storage::disk('public');

        $directories = $disk->directories();
        foreach ($directories as $directory) {
            $disk->deleteDirectory($directory);
        }

        $files = $disk->files();
        foreach ($files as $file) {
            if (! str_starts_with(basename($file), '.')) {
                $disk->delete($file);
            }
        }
    }
}
