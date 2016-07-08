<?php
/**
 * @category   IBM Confidential
 * @copyright  Copyright (C) 2015 International Business Machines Corp. - All Rights Reserved
 * @license    Unauthorized copying of this file, via any medium is strictly prohibited
 * @author     Written by Daniel Rodriguez <danrodri@mx1.ibm.com>, November 2015
 */

namespace IBM\Rtc;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Comment extends Model
{
    use Sanitizers;
    protected $rtc;
    private $description;
    private $creator;
    private $created;
    private $isAttachmentNotification;
    private $isCopyNotification;
    private $data;
    private $url;
    private $loaded = false;

    /**
     * @param string $url
     * @param Rtc    $rtc
     */
    public function __construct($url, Rtc $rtc)
    {
        $this->rtc = $rtc;
        $this->url = $url;
        parent::__construct();
    }

    public function __get($property)
    {
        if (($property != 'loaded' OR $property != 'url') AND !$this->loaded) {
            $this->preload();
        }
        return $this->$property;
    }

    /**
     * Preload the comment data to cache
     */
    private function preload()
    {
        if (!$this->loaded) {
            $comment = $this;
            $result = Cache::rememberForever('commentData-' . $comment->url, function () use ($comment) {
                $result = $comment->rtc->executeCurl($comment->url . '.json', false);
                return json_decode($result);
            });
            $this->populate($result);
        }
    }

    /**
     * fill the data into the comment
     *
     * @param \stdClass $comment
     */
    private function populate(\stdClass $comment)
    {
        $this->description = $this->sanitizeHtml($comment->{"dc:description"});
        $this->creator = $this->sanitizeNamespace($comment->{"dc:creator"}->{"rdf:resource"});
        $this->created = $this->formatTime($comment->{"dc:created"});
        $this->isAttachmentNotification = $this->isAttachment($this->description);
        $this->isCopyNotification = $this->isCopy($this->description);
        $this->data = collect([
            'description' => wordwrap($this->description),
            'creator'     => $this->creator,
            'created'     => $this->created,
        ]);
        $this->loaded = true;
    }

    /**
     * Check if the string is from an attachment
     *
     * @param string $str
     *
     * @return bool
     */
    private function isAttachment($str)
    {
        if (strstr($str, 'Added: attachment') !== false OR strstr($str, 'Removed: attachment') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Check if the comment is a notification of a copy
     *
     * @param string $str
     *
     * @return bool
     */
    private function isCopy($str)
    {
        if (strstr($str, 'Copied from work item') !== false) {
            return true;
        }

        return false;
    }

    /**
     * @return string
     */

    public function getDescription()
    {
        $this->preload();
        return $this->description;
    }

    /**
     * @return string
     */
    public function getCreator()
    {
        $this->preload();
        return $this->creator;
    }

    /**
     * @return string
     */
    public function getCreated()
    {
        $this->preload();
        return $this->created;
    }

    /**
     * @return boolean
     */
    public function getIsAttachmentNotification()
    {
        $this->preload();
        return $this->isAttachmentNotification;
    }

    /**
     * @return boolean
     */
    public function getIsCopyNotification()
    {
        $this->preload();
        return $this->isCopyNotification;
    }

    /**
     * @return array
     */
    public function getData()
    {
        $this->preload();
        return $this->data;
    }
}