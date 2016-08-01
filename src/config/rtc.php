<?php
/**
 * 
 * @copyright  Copyright (C) 2015 International Business Machines Corp. - All Rights Reserved
 * @license    MIT
 * @author     Written by Daniel Rodriguez <danrodri@mx1.ibm.com>, November 2015
 */
return [
    'host' => '', //https://domain.tld:port/jazz
    'namespace' => '', //https://domain.tld:port/jts/users/
    'user' => env('RTC_USERNAME', ''),
    'pass' => env('RTC_PASSWORD', ''),
    'count' => env('RTC_COUNT', false),
];