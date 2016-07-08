<?php
/**
 * @category   IBM Confidential
 * @copyright  Copyright (C) 2015 International Business Machines Corp. - All Rights Reserved
 * @license    MIT
 * @author     Written by Daniel Rodriguez <danrodri@mx1.ibm.com>, November 2015
 */

namespace IBM\Rtc;


trait Sanitizers
{
    /**
     * Need to change to fit your needs
     *
     * @var string
     */
    private $namespace;


    /**
     * @param string $str
     *
     * @return string
     */
    public function sanitizeHtml($str)
    {
        $withoutEntities = html_entity_decode($str);
        $withoutHtml = strip_tags($withoutEntities);

        return $withoutHtml;
    }

    /**
     * @param string $str
     *
     * @return string
     */
    public function sanitizeNamespace($str)
    {
        $config = config('rtc');
        $this->namespace = $config['namespace'];
        $withoutNamespace
            = str_replace($this->namespace, '', $str);
        $urlDecoded = urldecode($withoutNamespace);

        return $urlDecoded;
    }

    /**
     * @param string $str
     *
     * @return bool|string
     */
    public function formatTime($str)
    {
        if (empty($str)) {
            return "";
        }
        return date('m/d/y', strtotime($str));
    }

    /**
     * @param $prop
     *
     * @return null
     */
    public function setIfIsSet(&$prop)
    {
        if (isset($prop)) {
            return $prop;
        }
        return null;
    }
}