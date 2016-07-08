<?php
/**
 * @category   IBM Confidential
 * @copyright  Copyright (C) 2015 International Business Machines Corp. - All Rights Reserved
 * @license    MIT
 * @author     Written by Daniel Rodriguez <danrodri@mx1.ibm.com>, November 2015
 */

namespace IBM\Rtc;

use Cache;
use Log;
use \File;
use Storage;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

class Rtc
{

    public static $cookie = 'cookie.txt';
    public $total;
    public $now;
    protected $host;
    protected $user;
    protected $pass;
    private $workitem;
    private $query;
    private $created = false;

    /**
     *
     */
    public function __construct()
    {
        $config = config('rtc');
        $this->host = $config['host'];
        $this->user = $config['user'];
        $this->pass = $config['pass'];
        if (!Storage::exists(self::$cookie)
            OR Storage::lastModified(self::$cookie) < strtotime('-1 hours')
        ) {
            $this->authIdentity();
            $this->auth();
        }
    }

    /**
     * Relogin with the RTC Server
     */
    public function relogin()
    {
        $this->authIdentity();
        $this->auth();
    }

    /**
     * Generate the Cookie file
     */
    public function authIdentity()
    {
        $curl = curl_init();
        curl_setopt_array(
            $curl, [
            CURLOPT_URL            => $this->host . '/authenticated/identity',
            CURLOPT_COOKIEJAR      => storage_path('app/' . self::$cookie),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true
        ]
        );

        curl_exec($curl);

        curl_close($curl);
    }

    /**
     * Run the auth process
     *
     * @return string
     */
    public function auth()
    {
        $query = http_build_query(
            [
                'j_username' => $this->user,
                'j_password' => $this->pass,
            ]
        );
        
        $curl = curl_init();
        curl_setopt_array(
            $curl, [
            CURLOPT_URL            => $this->host
                . '/authenticated/j_security_check?',
            CURLOPT_COOKIEFILE     => storage_path('app/' . self::$cookie),
            CURLOPT_COOKIEJAR      => storage_path('app/' . self::$cookie),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST           => 1,
            CURLOPT_POSTFIELDS     => $query,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true
        ]
        );

        curl_exec($curl);

        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);


        if ($status != '200' AND $status != '302') {
            Log::critical(
                "The cURL status request is " . $status
                . " for security check"
            );
        }

        curl_close($curl);

        return $status;
    }

    /**
     * Delete the cookie file
     */
    public static function deleteCookie()
    {
        if (!Storage::exists(self::$cookie)) {
            Storage::delete(self::$cookie);
        }
    }

    /**
     * @param $id
     *
     * @return \IBM\Rtc\Query
     */
    public function query($id)
    {
        $rtc = $this;
        $this->query = Cache::rememberForever(
            'query-' . $id, function () use ($id, $rtc) {
            $result = $rtc->executeCurl('/oslc/queries/' . $id . '/rtc_cm:results.json');
            return new Query(json_decode($result), $rtc);
        }
        );

        return $this->query;
    }

    /**
     * @param           $url
     * @param bool|true $useHost
     *
     * @return string result
     */
    public function executeCurl($url, $useHost = true, $changeRequest = false)
    {

        $host = '';
        if ($useHost) {
            $host = $this->host;
        }

        $curl = curl_init();
        if ($changeRequest === false) {
            curl_setopt_array(
                $curl, [
                CURLOPT_URL              => $host . $url,
                CURLOPT_COOKIEFILE       => storage_path('app/' . self::$cookie),
                CURLOPT_COOKIEJAR        => storage_path('app/' . self::$cookie),
                CURLOPT_SSL_VERIFYPEER   => false,
                CURLOPT_RETURNTRANSFER   => true,
                CURLOPT_NOPROGRESS       => true,
                CURLOPT_PROGRESSFUNCTION => function ($clientp, $dltotal, $dlnow, $ultotal, $ulnow) {
                    if (!$this->created) {
                        $progress = new ProgressBar(new ConsoleOutput(1, true), $dltotal);
                        $progress->setFormat('verbose');
                        $progress->start();
                    }
                    $progress->setProgress($dlnow);
                    if ($dltotal == $dlnow) {
                        $progress->finish();
                    }
                }
            ]
            );
        } else {
            curl_setopt_array(
                $curl, [
                CURLOPT_URL              => $host . $url,
                CURLOPT_COOKIEFILE       => storage_path('app/' . self::$cookie),
                CURLOPT_COOKIEJAR        => storage_path('app/' . self::$cookie),
                CURLOPT_SSL_VERIFYPEER   => false,
                CURLOPT_RETURNTRANSFER   => true,
                CURLOPT_NOPROGRESS       => true,
                //CURLOPT_PUT             => 1,
                //                CURLOPT_CUSTOMREQUEST    => "PUT",
                CURLOPT_CUSTOMREQUEST    => "GET",
                //                CURLOPT_POSTFIELDS       => ['file' => '@' . $changeRequest],
                CURLOPT_HEADER           => [
//                    'Content-Type:application/json',
'Accept:application/x-oslc-cm-change-request+json',
//                                   "If-Match: \"<etagvalue>\""
                ],
                CURLOPT_PROGRESSFUNCTION => function ($clientp, $dltotal, $dlnow, $ultotal, $ulnow) {
                    if (!$this->created) {
                        $progress = new ProgressBar(new ConsoleOutput(1, true), $dltotal);
                        $progress->setFormat('verbose');
                        $progress->start();
                    }
                    $progress->setProgress($dlnow);
                    if ($dltotal == $dlnow) {
                        $progress->finish();
                    }
                }
            ]
            );
        }
        $result = curl_exec($curl);

        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($status != '200' AND $status != '302' AND $status != '403') {
            Log::critical(
                "The cURL status request is " . $status . " for "
                . $this->host . $url
            );
            exit("\nStatus \n" . $status);
        } elseif ($status == '403') {
            $this->authIdentity();
            $this->auth();
            Log::critical(
                "The cURL status request is " . $status . " for "
                . $this->host . $url
            );
            exit("\nStatus \n u: " . $this->user . "p: " . $this->pass . "\n\n" . $status);
        }

        curl_close($curl);

        if ($result == '') {
            Log::critical(
                "The cURL response is Empty for "
                . $this->host . $url
            );
            exit("\nEmpty response on $host$url\n");
        }

        if(!\File::exists(storage_path('app/'.date('Y-m-d').'.txt'))){
            File::put(storage_path('app/'.date('Y-m-d').'.txt'),0);
        }

        $counter = (int) File::get(storage_path('app/'.date('Y-m-d').'.txt'));

        $counter++;

        File::put(storage_path('app/'.date('Y-m-d').'.txt'),$counter);

        return $result;
    }

    /**
     * @param $id
     *
     * @return \IBM\Rtc\Workitem
     */
    public function workitem($id)
    {
        $rtc = $this;
        $this->workitem = Cache::rememberForever(
            'workitem-' . $id, function () use ($id, $rtc) {
            $result = $rtc->executeCurl('/oslc/workitems/' . $id . '.json');
            return new Workitem(json_decode($result), $rtc);
        }
        );

        return $this->workitem;
    }
}
