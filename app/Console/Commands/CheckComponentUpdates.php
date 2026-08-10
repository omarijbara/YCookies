<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Service;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Actions\Action;

class CheckComponentUpdates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ycookies:check-component-updates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks for component updates from the template library and notifies admins via Filament database notifications.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $services = Service::whereNotNull('template_version')->get();
        $updatesFound = [];

        foreach ($services as $service) {
            $update = $service->getAvailableUpdate();
            if ($update) {
                // Determine if we already found an update for this template
                $key = $service->template_key . '@' . $update;
                if (!isset($updatesFound[$key])) {
                    $updatesFound[$key] = [
                        'template' => $service->template_key,
                        'new_version' => $update,
                    ];
                }
            }
        }

        if (count($updatesFound) > 0) {
            $count = count($updatesFound);

            // Notify all admins/users
            $users = User::all();

            foreach ($users as $user) {
                Notification::make()
                    ->title("{$count} Component Updates Available")
                    ->body("New updates are available for your YCookies services from the template library.")
                    ->info()
                    ->actions([
                        Action::make('view')
                            ->label('Update Services')
                            ->button()
                            // Link to the services index where they can update it
                            ->url('/admin/' . ($user->groups->first()->id ?? 1) . '/services'), 
                    ])
                    ->sendToDatabase($user);

                if ($user->email) {
                    \Illuminate\Support\Facades\Mail::to($user->email)
                        ->send(new \App\Mail\TemplateUpdatesAvailable($updatesFound));
                }
            }

            $this->info("Found {$count} template update(s) available. Notifications sent to " . $users->count() . " users.");
        } else {
            $this->info("No template component updates available.");
        }

        return self::SUCCESS;
    }
}
