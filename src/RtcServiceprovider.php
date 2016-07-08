<?php
/**
 * @category   IBM Confidential
 * @copyright  Copyright (C) 2016 International Business Machines Corp. - All Rights Reserved
 * @license    MIT
 * @author     Written by Daniel Rodriguez <danrodri@mx1.ibm.com>, 01 2016
 */

namespace IBM\Rtc;

use Illuminate\Support\ServiceProvider;

class RtcServiceprovider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/config/rtc.php' => config_path('rtc.php'),
        ]);
    }

    public function register()
    {
        config([
            'config/rtc.php',
        ]);
        $this->app->bind('rtc', function ($app) {
            return new Rtc($app);
        });
    }
}