<?php
namespace App\Model;

use Nette,
    Nette\Utils\Strings,
    Nette\Security\Passwords,
    Tracy\Debugger,
    Exception,
    Nette\Utils\DateTime as DateTime,
    Nette\Database\Context;

/**
 * ItemStat global class
 */
class ItemStat extends Monda {
    
    static function itemSearch($key = false, $host = false, $hostgroup = false) {
        $iq = Array(
            "monitored" => true
        );
        if ($host) {
            $iq["host"] = $host;
        }
        if (Opts::getOpt("hostids")) {
            $iq["hostids"] = Opts::getOpt("hostids");
        }
        if ($hostgroup) {
            $iq["hostgroup"] = $hostgroup;
        }
        if ($key) {
            if (substr($key,0,1)!="@") {
                $iq["filter"] = Array("key_" => $key);
            } else {
                $iq["search"] = Array("key_" => substr($key,1));
            }
        }
        $i = Monda::apiCmd("itemGet",$iq);
        $ret = Array();
        foreach ($i as $item) {
            $ret[] = $item->itemid;
        }
        return($ret);
    }
    
    static function itemInfo($itemid) {
        $iq = Array(
            "monitored" => true,
            "output" => "extend",
            "itemids" => Array($itemid)
        );
        $item = Monda::apiCmd("itemGet",$iq);
        return($item);
    }
    
    function searchToIds() {
        $wids=Tw::searchToIds();
        
    }
    
    static function itemsToIds() {
        if (is_array(Opts::getOpt("items"))) {
            if (!is_array(Opts::getOpt("itemids"))) {
                $itemids=Array();
            } else {
                $itemids=Opts::getOpt("itemids");
            }
            foreach (Opts::getOpt("items") as $item) {
                $i=self::itemSearch($item,false,Opts::getOpt("hostgroups"));
                if (count($i)>0) {
                    $itemids=array_merge($itemids,$i);
                } else {
                    CliDebug::warn("Item $item not found! Continuing.\n");
                }
            }
            Opts::setOpt("itemids",$itemids);
        }
        CliDebug::dbg("Itemids selected: ".join(",",$itemids)."\n");
        return(Opts::getOpt("itemids"));
    }
    
    static function isSearch() {
        if (is_array(Opts::getOpt("itemids"))) {
            $itemidssql=sprintf("i.itemid IN (%s) AND",join(",",Opts::getOpt("itemids")));
        } else {
            $itemidssql="";
        }
        if (is_array(Opts::getOpt("hostids"))) {
            $hostidssql=sprintf("i.hostid IN (%s) AND",join(",",Opts::getOpt("hostids")));
        } else {
            $hostidssql="";
        }
        $wids=Tw::twToIds();
        if (count($wids)>0) {
            $windowidsql=sprintf("windowid IN (%s) AND",join(",",$wids));
        } else {
            return(false);
        }
        if (Opts::getOpt("max_rows")) {
            $limit="LIMIT ".Opts::getOpt("max_rows");
        } else {
            $limit="";
        }
        $rows=self::mquery(
                "SELECT i.itemid AS itemid,
                        i.min_ AS min_,
                        i.max_ AS max_,
                        i.avg_ AS avg_,
                        i.stddev_ AS stddev_,
                        i.loi AS loi,
                        i.cnt AS cnt,
                        i.hostid AS hostid,
                        i.cv AS cv,
                        i.windowid AS windowid
                    FROM itemstat i
                    JOIN timewindow tw ON (i.windowid=tw.id)
                 WHERE i.loi>? AND i.loi IS NOT NULL AND tw.loi>? AND tw.loi IS NOT NULL AND $itemidssql $hostidssql $windowidsql true
                ORDER by i.loi DESC "
                . "$limit",Opts::getOpt("is_minloi"),Opts::getOpt("tw_minloi")
                );
        if ($rows->getRowCount()==Opts::getOpt("max_rows")) {
            CliDebug::warn(sprintf("Limiting output of itemstats to %d! Use max_rows parameter to increase!\n",Opts::getOpt("max_rows")));
        }
        return($rows);
    }
    
    static function isStats() {
        Opts::setOpt("max_rows",Monda::_MAX_ROWS);
        $itemids=self::isToIds();
        $rows=self::mquery("SELECT 
                i.itemid AS itemid,
                        AVG(i.loi)::integer AS loi,
                        AVG(i.loi)*COUNT(i.windowid)::float AS loiw,
                        MIN(i.min_) AS min_,
                        MAX(i.max_) AS max_,
                        AVG(i.avg_) AS avg_,
                        AVG(i.stddev_) AS stddev_,
                        AVG(i.cnt)::integer AS cnt,
                        AVG(i.cv) AS cv,
                        COUNT(i.windowid) AS wcnt
                    FROM itemstat i
                 WHERE i.itemid IN (?)
                 AND i.loi IS NOT NULL
                 GROUP BY i.itemid
                 ORDER BY AVG(i.loi)*COUNT(i.windowid) DESC
                 LIMIT ?
                ",$itemids,Opts::getOpt("max_rows"));
        if ($rows->getRowCount()==Opts::getOpt("max_rows")) {
            CliDebug::warn(sprintf("Limiting output of itemstats to %d! Use max_rows parameter to increase!\n",Opts::getOpt("max_rows")));
        }
        return($rows->fetchAll());
    }
    
    static function isToIds($pkey=false) {
        $ids=self::isSearch();
        if (!$ids) {
            throw New Exception("No items found.");
        }
        $rows=$ids->fetchAll();
        $itemids=Array();
        $tmparr=Array();
        foreach ($rows as $row) {
            if ($pkey) {
                $itemids[]=Array(
                    "itemid" => $row->itemid,
                    "windowid" => $row->windowid,
                    "hostid" => $row->hostid);
                } else {
                    if (!array_key_exists($row->itemid,$tmparr)) {
                        $itemids[]=$row->itemid;
                        $tmparr[$row->itemid]=true;
                    }
                }
        }
        return($itemids);
    }
        
    static function isCompute($wids) {
        
        if (!$wids) {
            throw New Exception("No windows to process.");
        }
        $windows=Tw::twGet($wids,true);

        $widstxt=join(",",$wids);
        $ttable="mwtmp_".rand(1000,9999);
        $crsql="CREATE TEMPORARY TABLE $ttable (s integer, e integer, id integer);";
        Monda::zquery($crsql);
        foreach ($windows as $w) {
            $crsqli="INSERT INTO $ttable VALUES ($w->fstamp,$w->tstamp,$w->id);\n";
            Monda::zquery($crsqli);
            $crsql.=$crsqli;
        }
        $wstats=Tw::twStats();
        CliDebug::warn(sprintf("Computing item statistics (zabbix_id: %d, from %s to %s (%d) windows...",Opts::getOpt("zabbix_id"),Util::dateTime($wstats->minfstamp),Util::dateTime($wstats->maxtstamp),count($wids)));
        $items=self::isToIds();
        if (count($items)>0) {
            $itemidsql=sprintf("AND itemid IN (%s)",join(",",$items));
        } else {
            $itemidsql="";
        }
        $rows=self::zquery(
            "SELECT w.id AS windowid,
                 itemid AS itemid,
                    min(value) AS min_,
                    max(value) AS max_,
                    avg(value) AS avg_,
                    stddev(value) AS stddev_,
                    count(*) AS cnt
                 FROM
                 (
                  SELECT itemid,clock,value FROM ".Opts::getOpt("zabbix_history_table")."
                  WHERE clock BETWEEN ? AND ?
                  UNION ALL
                  SELECT itemid,clock,value FROM ".Opts::getOpt("zabbix_history_uint_table")."
                  WHERE clock BETWEEN ? AND ?
                 ) AS h
                 JOIN $ttable w ON (clock BETWEEN w.s AND w.e)
                  $itemidsql
                 GROUP BY windowid,itemid
                 ORDER BY windowid,itemid",
                $wstats->minfstamp,$wstats->maxtstamp,$wstats->minfstamp,$wstats->maxtstamp);
        self::mbegin();
        self::mquery("DELETE FROM itemstat WHERE windowid IN (?)",$wids);
        Monda::sreset();
        $wid=false;
        $sumcv=0;
        $sumcnt=0;
        while ($row=$rows->fetch()) {
            CliDebug::info(".");
            Monda::sadd("found");
            if ($row->stddev_<=Opts::getOpt("min_stddev")) {
                Monda::sadd("ignored");
                Monda::sadd("lowstddev");
                continue;   
            }
            if ($row->cnt<Opts::getOpt("min_values_per_window")) {
                Monda::sadd("ignored");
                Monda::sadd("lowcnt");
                continue;
            }
            if ($row->avg_>Opts::getOpt("min_avg_for_cv")) {
                $cv=$row->stddev_/$row->avg_;
                if ($cv<=Opts::getOpt("min_cv")) {
                    Monda::sadd("ignored");
                    Monda::sadd("lowcv");
                    continue;
                }
            } else {
                Monda::sadd("ignored");
                Monda::sadd("lowavg");
                continue;
            }
            Monda::sadd("processed");
            $sumcv+=$cv;
            $sumcnt+=$row->cnt;
            Monda::mquery("INSERT INTO itemstat "
                    . "       (windowid,    itemid, min_,   max_,   avg_,   stddev_,    cnt,    cv) "
                    . "VALUES (?       ,    ?,      ?,      ?,      ?,      ?,          ?,      ?)",
                        $row->windowid,
                        $row->itemid,
                        $row->min_,
                        $row->max_,
                        $row->avg_,
                        $row->stddev_,
                        $row->cnt,
                        $cv
                    );
            if ($wid!=$row->windowid) {
                if ($wid) {
                    Monda::mquery("UPDATE timewindow
                    SET updated=?, found=?, processed=?, ignored=?, lowcnt=?, lowavg=?, lowstddev=?, lowcv=?, avgcv=?, avgcnt=?
                    WHERE id=?",
                    New DateTime(),
                    Monda::sget("found"),
                    Monda::sget("processed"),
                    Monda::sget("ignored"),
                    Monda::sget("lowcnt"),
                    Monda::sget("lowavg"),
                    Monda::sget("lowstddev"),
                    Monda::sget("lowcv"),
                    $sumcv/Monda::sget("processed"),
                    $sumcnt/Monda::sget("found"),
                    $wid);
                }
                Monda::sreset();
                $sumcv=0;
                $sumcnt=0;
                $wid=$row->windowid;
            }
        }
        if (Monda::sget("found")>0) {
            if ($wid) {
                Monda::mquery("UPDATE timewindow
                    SET updated=?, found=?, processed=?, ignored=?, lowcnt=?, lowavg=?, lowstddev=?, lowcv=?, avgcv=?, avgcnt=?
                    WHERE id=?",
                    New DateTime(),
                    Monda::sget("found"),
                    Monda::sget("processed"),
                    Monda::sget("ignored"),
                    Monda::sget("lowcnt"),
                    Monda::sget("lowavg"),
                    Monda::sget("lowstddev"),
                    Monda::sget("lowcv"),
                    $sumcv/Monda::sget("processed"),
                    $sumcnt/Monda::sget("found"),
                    $wid);
            }          
            $ret=Monda::sget();
        } else {
            $ret=false;
        }
        Monda::mcommit();
        Monda::zquery("DROP TABLE $ttable");
        CliDebug::warn("Done.\n");
        return($ret);
    }
    
    static public function IsZabbixHistory() {
        $itemids = Opts::getOpt("itemids");
        $windowids = Tw::twToIds();
        $timesql = "";
        $from=time();
        $to=0;
        foreach ($windowids as $wid) {
            $w = Tw::twGet($wid);
            $timesql.="OR (clock BETWEEN $w->fstamp AND $w->tstamp) ";
            $from=min($from,$w->fstamp);
            $to=max($to,$w->tstamp);
        }
        $g = Opts::getOpt("history_granularity");
        $hist = Monda::zcquery("                
              SELECT itemid,CAST((clock/$g) AS INTEGER)*$g AS c,AVG(value) AS v FROM history WHERE (false $timesql) AND itemid IN (?)
                GROUP BY itemid,CAST((clock/$g) AS INTEGER)*$g
              UNION ALL 
              SELECT itemid,CAST((clock/$g) AS INTEGER)*$g AS c,AVG(value) AS v FROM history_uint WHERE (false $timesql) AND itemid IN (?)
                GROUP BY itemid,CAST((clock/$g) AS INTEGER)*$g
              ORDER BY c,itemid
                ", $itemids, $itemids);
        $ret = Array();
        foreach ($hist as $h) {
            foreach ((array) $h as $c) {
                $values[$h->itemid][$h->c] = $h->v;
            }
        }
        if (Opts::getOpt("history_interpolate")) {
            $x = range($from, $to, $g);
            $ip = Array();
            foreach ($values as $itemid => $column) {
                CliDebug::info(sprintf("Interpolating %d values for %s from %d values.\n", count($x), $itemid, count($column)));
                $ip[$itemid] = Util::interpolate($column, $x);
            }
            foreach ($x as $x1) {
                foreach ($ip as $itemid => $values) {
                    $ret[$x1]["clock"] = $x1;
                    $ret[$x1][$itemid] = $values[$x1];
                }
            }
        } else {
            foreach ($hist as $h) {
                $ret[$h->c]["clock"] = $h->c;
                $ret[$h->c][$h->itemid] = $h->v;
            }
        }
        return($ret);
    }

    static public function IsMultiCompute() {
        Opts::setOpt("window_empty", true);
        Opts::setOpt("tw_minloi", -1);
        $wids = Tw::twToIds();
        if (!$wids)
            return;
        if (Opts::getOpt("max_windows_per_query") && count($wids) > Opts::getOpt("max_windows_per_query")) {
            foreach (array_chunk($wids, Opts::getOpt("max_windows_per_query")) as $subwids) {
                self::isCompute($subwids);
            }
        } else {
            self::isCompute($wids);
        }
    }

    static public function IsDelete() {
        $items=self::IsToIds();
        $windowids=Tw::TwtoIds();
        CliDebug::warn(sprintf("Will delete %d itemstat entries (%d windows)...",count($items),count($windowids)));
        if (count($items)>0 && count($windowids)>0) {
            self::mbegin();
            self::mquery("DELETE FROM itemstat WHERE ?", $items);
            self::mquery("UPDATE timewindow SET updated=NULL,loi=0 WHERE id IN (?)",$windowids);
            self::mcommit();
        }
        CliDebug::warn("Done\n");
    }
    
    static public function IsShow() {
        $stats=self::mquery("
            SELECT 
                MIN(value),MAX(value),
            FROM itemstat
            JOIN timewindow ON (id=windowid)
            WHERE timewindow.serverid=?
            GROUP BY itemid
            ",Opts::getOpt("zabbix_id"));
        return($stats);
    }
    
    static public function IsLoi() {
        $wids=Tw::twToIds();
        CliDebug::warn(sprintf("Need to compute itemstat loi for %d windows...",count($wids)));
        if (count($wids)>0) {
            $lsql=self::mquery("
                UPDATE itemstat 
                SET loi=cnt*(cv/?)
                WHERE windowid IN (?)
                ",Opts::getOpt("max_cv"),$wids);
        }
        CliDebug::warn("Done\n");
    }
}

?>
