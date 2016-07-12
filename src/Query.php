<?php
/**
 * 
 * @copyright  Copyright (C) 2015 International Business Machines Corp. - All Rights Reserved
 * @license    MIT
 * @author     Written by Daniel Rodriguez <danrodri@mx1.ibm.com>, November 2015
 */

namespace IBM\Rtc;

use Illuminate\Support\Facades\Cache;

class Query
{
    use Sanitizers;
    public $workitems;
    private $rtc;

    /**
     * @param     $workitems
     * @param Rtc $rtc
     */
    public function __construct($workitems, Rtc $rtc)
    {
        $this->workitems = $workitems;
        $this->rtc = $rtc;
        if (empty($workitems)) {
            \Log::critical("Query not found. Does the workitem exists and we have access?");
        }
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function getWorkItems()
    {
        $items = [];
        $workitems = $this->workitems;
        $rtc = $this->rtc;
        foreach ($workitems as $workitem) {
            $item = Cache::rememberForever('workitem-' . $workitem->{"dc:identifier"},
                function () use ($workitem, $rtc) {
                    return new Workitem($workitem, $rtc);
                });
            $items[] = $item;
        }
        $this->workitems = collect($items);
        return $this->workitems;
    }
}