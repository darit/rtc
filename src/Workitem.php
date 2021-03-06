<?php
/**
 * 
 * @copyright  Copyright (C) 2015 International Business Machines Corp. - All Rights Reserved
 * @license    MIT
 * @author     Written by Daniel Rodriguez <danrodri@mx1.ibm.com>, November 2015
 */

namespace IBM\Rtc;

use Illuminate\Support\Facades\Cache;

class Workitem
{
    use Sanitizers;
    public $id;
    public $title;
    public $comments;
    public $creator;
    public $created;
    public $owner;
    public $start;
    public $due;
    public $tags;
    public $severity;
    public $priority;
    public $resolved;
    public $status;
    public $planned = null;
    public $progress;
    public $children;
    public $incident_age;
    public $incident_count;
    public $incidence_new;
    public $watch_count;
    public $tolerance_cnt;
    public $tolerance_age;
    private $rtc;
    private $data;
    private $workitem;

    /**
     * @param \stdClass  $workitem
     * @param Rtc        $rtc
     * @param bool|false $lazyLoadComments
     */
    public function __construct(\stdClass $workitem, Rtc $rtc, $lazyLoadComments = false)
    {
        $this->rtc = $rtc;
        $this->workitem = $workitem;

        if (!isset($workitem->{"dc:title"})) {
            \Log::critical("Title not found. Does the workitem exists and we have access?");
            Rtc::deleteCookie();
            exit("Title not found. Does the workitem exists and we have access?\r\n");
        }
        $this->id = $workitem->{"dc:identifier"};
        $this->title = $this->sanitizeHtml($workitem->{"dc:title"});
        $this->description = $this->sanitizeHtml($workitem->{"dc:description"});
        $this->owner = $this->sanitizeNamespace($workitem->{"rtc_cm:ownedBy"}->{"rdf:resource"});
        $this->creator = $this->sanitizeNamespace($workitem->{"dc:creator"}->{"rdf:resource"});
        $this->created = $this->formatTime($workitem->{"dc:created"});
        if (isset($workitem->{"rtc_cm:start"})) {
            $this->start = $this->formatTime($workitem->{"rtc_cm:start"});
        }
        if (isset($workitem->{"rtc_cm:due"})) {
            $this->due = $this->formatTime($workitem->{"rtc_cm:due"});
        }
        $comments = [];
        foreach ($workitem->{"rtc_cm:comments"} as $comment) {
            if (is_object($comment) AND !$lazyLoadComments) {
                $comments[] = Cache::rememberForever(
                    'comment-' . $comment->{"rdf:resource"},
                    function () use ($comment, $rtc) {
                        return new Comment($comment->{"rdf:resource"}, $rtc);
                    }
                );
            }
        }
        $this->comments = collect($comments);

        $tags = explode(',', $workitem->{"dc:subject"});

        $this->tags = collect($tags);

        $this->severity = $this->severity($workitem->{"oslc_cm:severity"}->{"rdf:resource"}, $rtc);
        $this->priority = $this->priority($workitem->{"oslc_cm:priority"}->{"rdf:resource"}, $rtc);
        $this->progress = $this->progress($workitem->{"rtc_cm:progressTracking"}->{"rdf:resource"}, $rtc);
        $this->status = $this->status($workitem->{"rtc_cm:state"}->{"rdf:resource"}, $rtc);
        if (!is_null($workitem->{"rtc_cm:plannedFor"})) {
            $this->planned = $this->planned($workitem->{"rtc_cm:plannedFor"}->{"rdf:resource"}, $rtc);
        }
        $this->detail = '';
        if (isset($workitem->{"rtc_cm:statusdetails"})) {
            $this->detail = $workitem->{"rtc_cm:statusdetails"};
        }
        $this->type = $this->type($workitem->{"dc:type"}->{"rdf:resource"}, $rtc);
        if (isset($workitem->{"oslc_cm:resolved"})) {
            $this->resolved = $this->formatTime($workitem->{"oslc_cm:resolved"});
        }
        if (isset($workitem->{"rtc_cm:incident_age"})) {
            $this->incident_age = $workitem->{"rtc_cm:incident_age"};
        }
        if (isset($workitem->{"rtc_cm:incident_count"})) {
            $this->incident_count = $workitem->{"rtc_cm:incident_count"};
        }
        if (isset($workitem->{"rtc_cm:incidence_new"})) {
            $this->incidence_new = $workitem->{"rtc_cm:incidence_new"};
        }
        if (isset($workitem->{"rtc_cm:watch_count"})) {
            $this->watch_count = $workitem->{"rtc_cm:watch_count"};
        }
        if (isset($workitem->{"rtc_cm:tolerance_cnt"})) {
            $this->tolerance_cnt = $workitem->{"rtc_cm:tolerance_cnt"};
        }
        if (isset($workitem->{"rtc_cm:tolerance_age"})) {
            $this->tolerance_age = $workitem->{"rtc_cm:tolerance_age"};
        }
        

        $this->children = $this->loadChildren();

        $this->data = collect(
            [
                'id'          => $this->id,
                'title'       => wordwrap($this->title, 30),
                'status'      => wordwrap($this->status, 30),
                'detail'      => wordwrap($this->detail, 30),
                'description' => wordwrap($this->description, 50),
                'severity'    => $this->severity,
                'priority'    => $this->priority,
                'tags'        => implode(',', $this->tags->toArray()),
                'owner'       => $this->owner,
                //            'creator'     => $this->creator,
                'start'       => $this->start,
                'due'         => $this->due,
                'defects'     => $this->children['defects'],
                'stories'     => $this->children['stories'],
                'tasks'       => $this->children['tasks'],
            ]
        );
    }

    /**
     * @param string $resource
     * @param Rtc    $rtc
     *
     * @return string
     */
    private function severity($resource, Rtc $rtc)
    {
        $response = Cache::rememberForever(
            'resource-' . $resource,
            function () use ($resource, $rtc) {
                return $rtc->executeCurl($resource . '.json', false);
            }
        );
        $severity = json_decode($response);
        return $severity->{"dc:title"};
    }

    /**
     * @param string $resource
     * @param Rtc    $rtc
     *
     * @return string
     */
    private function priority($resource, Rtc $rtc)
    {
        $response = Cache::rememberForever(
            'resource-' . $resource,
            function () use ($resource, $rtc) {
                return $rtc->executeCurl($resource . '.json', false);
            }
        );
        $priority = json_decode($response);
        return $priority->{"dc:title"};
    }

    /**
     * @param string $resource
     * @param Rtc    $rtc
     *
     * @return \Illuminate\Support\Collection
     */
    private function progress($resource, Rtc $rtc)
    {
        $response = Cache::rememberForever(
            'resource-' . $resource,
            function () use ($resource, $rtc) {
                return $rtc->executeCurl($resource . '.json', false);
            }
        );
        $priority = json_decode($response);
        return collect(
            [
                'workCompleted'                 => $priority->{"oslc_pl:workCompleted"},
                'itemsRemainingEffortEstimated' => $priority->{"oslc_pl:itemsRemainingEffortEstimated"},
                'itemsCompleted'                => $priority->{"oslc_pl:itemsCompleted"},
                'itemsRemainingSizingEstimated' => $priority->{"oslc_pl:itemsRemainingSizingEstimated"},
                'sizingUnitsShortLabel'         => $priority->{"oslc_pl:sizingUnitsShortLabel"},
                'effortRemaining'               => $priority->{"oslc_pl:effortRemaining"},
                'itemsRemaining'                => $priority->{"oslc_pl:itemsRemaining"},
                'sizingUnitsCompleted'          => $priority->{"oslc_pl:sizingUnitsCompleted"},
                'planDurationCompleted'         => $priority->{"oslc_pl:planDurationCompleted"},
                'sizingUnitsLabel'              => $priority->{"oslc_pl:sizingUnitsLabel"},
                'sizingUnitsRemaining'          => $priority->{"oslc_pl:sizingUnitsRemaining"},
                'planDurationRemaining'         => $priority->{"oslc_pl:planDurationRemaining"},
            ]
        );
    }

    /**
     * @param string $resource
     * @param Rtc    $rtc
     *
     * @return string
     */
    private function status($resource, Rtc $rtc)
    {
        $response = Cache::rememberForever(
            'resource-' . $resource,
            function () use ($resource, $rtc) {
                return $rtc->executeCurl($resource . '.json', false);
            }
        );
        $status = json_decode($response);
        return $status->{"dc:title"};
    }

    /**
     * @param string $resource
     * @param Rtc    $rtc
     *
     * @return string
     */
    private function planned($resource, Rtc $rtc)
    {
        $response = Cache::rememberForever(
            'resource-' . $resource,
            function () use ($resource, $rtc) {
                return $rtc->executeCurl($resource . '.json', false);
            }
        );
        $status = json_decode($response);
        return $status->{"rtc_cm:endDate"};
    }

    /**
     * @param string $resource
     * @param Rtc    $rtc
     *
     * @return string
     */
    private function type($resource, Rtc $rtc)
    {
        $response = Cache::rememberForever(
            'resource-' . $resource,
            function () use ($resource, $rtc) {
                return $rtc->executeCurl($resource . '.json', false);
            }
        );
        $status = json_decode($response);
        return $status->{"dc:title"};
    }

    /**
     * Load comments of workitem to cache
     */
    public function loadComments()
    {
        $comments = [];
        $ths = $this;
        foreach ($this->workitem->{"rtc_cm:comments"} as $comment) {
            if (is_object($comment)) {
                $comments[] = Cache::rememberForever(
                    'comment-' . $comment->{"rdf:resource"},
                    function () use ($comment, $ths) {
                        return new Comment($comment->{"rdf:resource"}, $ths->rtc);
                    }
                );
            }
        }
        $this->comments = collect($comments);
    }

    /**
     * @return array
     */
    private function loadChildren()
    {
        $children = [
            'defects' => [],
            'stories' => [],
            'tasks'   => [],
        ];
        if(isset($this->workitem->{"rtc_cm:com.ibm.team.workitem.linktype.parentworkitem.children"})){
            foreach ($this->workitem->{"rtc_cm:com.ibm.team.workitem.linktype.parentworkitem.children"} as $child) {
                $idParts = explode('/', $child->{"rdf:resource"});
                $id = end($idParts);
                $id = trim($id);
                $result = $this->rtc->executeCurl('/oslc/workitems/' . $id . '.json');
                $json = json_decode($result);
                $rtc = $this->rtc;
                $item = Cache::rememberForever(
                    'workitem-' . $json->{"dc:identifier"}, function () use ($rtc, $json) {
                    return new self($json, $rtc, true);
                }
                );
                if (isset($json->{"rtc_cm:storypts"})) {
                    $children['stories'][] = $item;
                } elseif (isset($json->{"rtc_cm:defectcategory"})) {
                    $children['defects'][] = $item;
                } else {
                    $children['tasks'][] = $item;
                }
            }
        }
        if(isset($this->workitem->{"rtc_cm:com.ibm.team.workitem.linktype.parentworkitem.related"})){
            foreach ($this->workitem->{"rtc_cm:com.ibm.team.workitem.linktype.parentworkitem.related"} as $child) {
                $idParts = explode('/', $child->{"rdf:resource"});
                $id = end($idParts);
                $id = trim($id);
                $result = $this->rtc->executeCurl('/oslc/workitems/' . $id . '.json');
                $json = json_decode($result);
                $rtc = $this->rtc;
                $item = Cache::rememberForever(
                    'workitem-' . $json->{"dc:identifier"}, function () use ($rtc, $json) {
                    return new self($json, $rtc, true);
                }
                );
                if (isset($json->{"rtc_cm:storypts"})) {
                    $children['stories'][] = $item;
                } elseif (isset($json->{"rtc_cm:defectcategory"})) {
                    $children['defects'][] = $item;
                } else {
                    $children['tasks'][] = $item;
                }
            }
        }

        return $children;
    }

    /**
     * Return the comments minus the notifications
     *
     * @return array
     */
    public function getCommentsWithoutNotifications()
    {
        $comments = [];
        if (!$this->comments->isEmpty()) {
            foreach ($this->comments as $comment) {
                if (is_object($comment) AND !$comment->getIsAttachmentNotification()
                    AND !$comment->getIsCopyNotification()
                ) {
                    $comments[] = $comment;
                }
            }
        }
        return $comments;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function getComments()
    {
        return $this->comments;
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    public function update(array $changes)
    {
        $result = $this->rtc->executeCurl('/oslc/workitems/' . $this->id, true, $changes);

        return $result;
    }
}