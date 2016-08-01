<?php
/**
 *
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
        $this->count = env('RTC_COUNT', false);
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
                CURLOPT_URL => $this->host . '/authenticated/identity',
                CURLOPT_COOKIEJAR => storage_path('app/' . self::$cookie),
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
                CURLOPT_URL => $this->host
                    . '/authenticated/j_security_check?',
                CURLOPT_COOKIEFILE => storage_path('app/' . self::$cookie),
                CURLOPT_COOKIEJAR => storage_path('app/' . self::$cookie),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => $query,
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
                    CURLOPT_URL => $host . $url,
                    CURLOPT_COOKIEFILE => storage_path('app/' . self::$cookie),
                    CURLOPT_COOKIEJAR => storage_path('app/' . self::$cookie),
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_NOPROGRESS => true,
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
            foreach($changeRequest as $field => $value){
                $newField = str_replace('rtc_cm','rtc_ext', $field);
                unset($changeRequest[$field]);
                $changeRequest[$newField] = $value;
            }
            $properties = implode(',', array_keys($changeRequest));
            $etag = $this->getEtag($url, $useHost, $changeRequest);
            //$payload = $this->generateChangeRequest($host . $url, $changeRequest);
            $payload = json_encode($changeRequest);
            curl_setopt_array(
                $curl, [
                    CURLOPT_URL => $host . $url . '?oslc.properties=' . $properties,
                    CURLOPT_COOKIEFILE => storage_path('app/' . self::$cookie),
                    CURLOPT_COOKIEJAR => storage_path('app/' . self::$cookie),
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_VERBOSE => true,
                    CURLOPT_HEADER => true,
                    CURLINFO_HEADER_OUT => true,
                    CURLOPT_POST => 1,
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($payload),
                        'If-Match: ' . $etag,
                        'Accept: application/json',
                        'OSLC-Core-Version: 2.0'
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
        $requestHeaders = curl_getinfo($curl, CURLINFO_HEADER_OUT);


        $info = curl_getinfo($curl);
        curl_close($curl);

        $header = substr($result, 0, $info['header_size']);

        if ($status != '200' AND $status != '302' AND $status != '403') {
            Log::critical(
                "The cURL status request is " . $status . " for "
                . $this->host . $url
            );
            echo $requestHeaders;
            echo $header;
            echo $result;
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

        if ($result == '') {
            Log::critical(
                "The cURL response is Empty for "
                . $this->host . $url
            );
            exit("\nEmpty response on $host$url\n");
        }

        if($this->count) {
            if (!\File::exists(storage_path('app/' . date('Y-m-d') . '.txt'))) {
                File::put(storage_path('app/' . date('Y-m-d') . '.txt'), 0);
            }

            $counter = (int)File::get(storage_path('app/' . date('Y-m-d') . '.txt'));

            $counter++;

            File::put(storage_path('app/' . date('Y-m-d') . '.txt'), $counter);
        }

        return $result;
    }


    public function requestHeaders($url, $useHost = true, $changeRequest)
    {

        $host = '';
        if ($useHost) {
            $host = $this->host;
        }

        $curl = curl_init();
        $properties = implode(',', array_keys($changeRequest));
        curl_setopt_array(
            $curl, [
                CURLOPT_URL => $host . $url . '.json?oslc.properties=' . $properties . '&oslc.prefix=rtc_cm=%3Chttp://jazz.net/xmlns/prod/jazz/rtc/cm/1.0/%3E',
                CURLOPT_COOKIEFILE => storage_path('app/' . self::$cookie),
                CURLOPT_COOKIEJAR => storage_path('app/' . self::$cookie),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADER => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLINFO_HEADER_OUT => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'OSLC-Core-Version: 2.0',
                ],
            ]
        );

        $result = curl_exec($curl);

        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $requestHeaders = curl_getinfo($curl, CURLINFO_HEADER_OUT);

        $info = curl_getinfo($curl);

        if ($status != '200' AND $status != '302' AND $status != '403') {
            Log::critical(
                "The cURL status request is " . $status . " for "
                . $this->host . $url
            );
            echo $requestHeaders;
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

        $header = substr($result, 0, $info['header_size']);

        if ($result == '') {
            Log::critical(
                "The cURL response is Empty for "
                . $this->host . $url
            );
            exit("\nEmpty response on $host$url\n");
        }

        if($this->count) {

            if (!\File::exists(storage_path('app/' . date('Y-m-d') . '.txt'))) {
                File::put(storage_path('app/' . date('Y-m-d') . '.txt'), 0);
            }

            $counter = (int)File::get(storage_path('app/' . date('Y-m-d') . '.txt'));

            $counter++;

            File::put(storage_path('app/' . date('Y-m-d') . '.txt'), $counter);
        }

        return $header;
    }


    public function getEtag($url, $useHost = true, $changeRequest)
    {
        $headers = $this->requestHeaders($url, $useHost, $changeRequest);
        $matches = [];
        preg_match('/(?:.*)ETag: "(.*)"/i',
            $headers, $matches);
        $etag = '';
        if (isset($matches[1])) {
            $etag = $matches[1];
        }

        return $etag;
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

    public function updateWorkitem($id, array $changes)
    {
        $rtc = $this;
        Cache::forget('workitem-' . $id);
        $this->workitem = Cache::rememberForever(
            'workitem-' . $id, function () use ($id, $rtc, $changes) {
            $result = $rtc->executeCurl('/oslc/workitems/' . $id, true, $changes);
            return new Workitem(json_decode($result), $rtc);
        }
        );

        return $this->workitem;
    }
}
