<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Process;

class Developer extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';
    protected static ?string $title = 'Developer Tools';
    protected static ?string $slug = 'developer';
    protected string $view = 'filament.pages.developer';
    protected static bool $shouldRegisterNavigation = false;

    public string $buildOutput = '';
    public bool $isBuilding = false;

    public function recompileAssets(): void
    {
        $this->isBuilding = true;
        $this->buildOutput = '';

        try {
            $result = Process::path(base_path())
                ->timeout(120)
                ->run('npx vite build');

            $this->buildOutput = $result->output() . $result->errorOutput();

            if ($result->successful()) {
                Notification::make()
                    ->title('Assets recompiled successfully')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Asset compilation failed')
                    ->body('Check the output log for details.')
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            $this->buildOutput = $e->getMessage();
            Notification::make()
                ->title('Error running build')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->isBuilding = false;
    }

    public function clearAllCaches(): void
    {
        try {
            $commands = ['config:clear', 'route:clear', 'view:clear', 'cache:clear'];
            $output = [];

            foreach ($commands as $cmd) {
                $result = Process::path(base_path())
                    ->timeout(30)
                    ->run("php artisan {$cmd}");
                $output[] = trim($result->output());
            }

            $this->buildOutput = implode("\n", $output);

            Notification::make()
                ->title('All caches cleared')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error clearing caches')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function runMigrations(): void
    {
        try {
            $result = Process::path(base_path())
                ->timeout(60)
                ->run('php artisan migrate --force');

            $this->buildOutput = $result->output() . $result->errorOutput();

            if ($result->successful()) {
                Notification::make()
                    ->title('Migrations completed')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Migration failed')
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            $this->buildOutput = $e->getMessage();
            Notification::make()
                ->title('Error running migrations')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
