<?php

namespace Dashed\DashedFiles;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class DashedFilesEventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        \Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent::class => [
            \Dashed\DashedFiles\Listeners\ListenForNewConversions::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
