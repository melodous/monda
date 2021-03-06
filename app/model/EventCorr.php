<?php

namespace App\Model;

use Nette,
    Nette\Utils\Strings,
    Nette\Security\Passwords,
    Nette\Diagnostics\Debugger,
    Nette\Database\Context,
    \ZabbixApi;

/**
 * ItemStat global class
 */
class EventCorr extends Monda {

    function ecSearch($start) {
        if ($start+Monda::_1DAY>Opts::getOpt("end")) {
            $end=Opts::getOpt("end");
        } else {
            $end=$start+Monda::_1DAY;
        }
        CliDebug::warn(sprintf("Searching for events between <%s,%s> ", Util::dateTime($start),Util::dateTime($end)));
        $eq=Array(
            "time_from" => $start,
            "time_till" => $end,
            "output" => "extend",
            "selectHosts" => "refer",
            "selectRelatedObject" => "refer",
            "select_alerts" => "refer",
            "select_acknowledges" => "refer"
        );
        $events=self::apiCmd("eventGet",$eq);
        CliDebug::warn(sprintf("Found %d events, updating LOI.\n",count($events)));
        return($events);
    }
    
    function ecLoi() {
        $start = Opts::getOpt("start");
        $wstats1 = Tw::twStats();
        $isstats1 = ItemStat::isStats();
        self::mbegin();
        while ($start < Opts::getOpt("end")) {
            $events = self::ecSearch($start);
            $start += Monda::_1DAY;
            if (count($events) == 0) {
                return;
            }
            foreach ($events as $e) {
                if ($e->source!=0) continue;
                $continue=0;
                if (isset($e->relatedObject->triggerid)) {
                    $tq=Array(
                        "triggerids" => $e->relatedObject->triggerid,
                        "hostids" => Opts::getOpt("hostids"),
                        "selectFunctions" => "extend",
                        "output" => "extend"
                    );
                    $triggers=self::apiCmd("triggerGet",$tq);
                    foreach ($triggers as $t) {
                        if ($t->priority<Opts::getOpt("ec_min_priority")) {
                            $continue=1;
                            CliDebug::info(sprintf("Skipping event %d due to low priority (%d).\n",$e->eventid,$t->priority));
                            continue;
                        }
                    }
                }
                if ($continue) continue;
                $w = Tw::twSearchClock($e->clock)->fetchAll();
                $wids = self::extractIds($w, array("id"));
                self::mquery("UPDATE timewindow SET loi=loi+? WHERE id IN (?)", Opts::getOpt("ec_window_increment_loi"), $wids["id"]);
                if (isset($e->hosts)) {
                    foreach ($e->hosts as $h) {
                        self::mquery("UPDATE hoststat SET loi=loi+? WHERE hostid=? AND windowid IN (?)", Opts::getOpt("ec_host_increment_loi"), $h->hostid, $wids["id"]);
                    }
                }
                if (isset($e->items)) {
                    foreach ($e->items as $i) {
                        self::mquery("UPDATE itemstat SET loi=loi+? WHERE hostid=? AND windowid IN (?)", Opts::getOpt("ec_item_increment_loi"), $i->itemid, $wids["id"]);
                    }
                }
            }
        }
        self::mcommit();
        $wstats2 = Tw::twStats();
        $isstats2 = ItemStat::isStats();
    }

}

?>
