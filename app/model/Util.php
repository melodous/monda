<?php

namespace App\Model;

use Nette,
    Nette\Utils\Strings,
    Nette\Utils\DateTime as DateTime,
    Tracy\Debugger;

/**
 * TimeWindow global class
 */
class Util extends Nette\Object {

   const TW_STEP=300;
    
   static function timetoseconds($t) {
        if ($t[0] == "@") {
            return(substr($t, 1));
        } elseif (is_numeric($t)) {
            return($t);
        } elseif (preg_match("/(\d\d\d\d)\_(\d\d)\_(\d\d)\_(\d\d)(\d\d)/", $t, $r)) {
            $y = $r[1];
            $m = $r[2];
            $d = $r[3];
            $h = $r[4];
            $M = $r[5];
            $dte = New DateTime("$y-$m-$d $h:$M".date("P"));
            return(date_format($dte, "U"));
        } elseif (preg_match("/(\d\d\d\d)(\d\d)(\d\d)(\d\d)(\d\d)/", $t, $r)) {
            $y = $r[1];
            $m = $r[2];
            $d = $r[3];
            $h = $r[4];
            $M = $r[5];
            $dte = New DateTime("$y-$m-$d $h:$M".date("P"));
            return(date_format($dte, "U"));
        } else {
            $dte = New DateTime($t);
            return(date_format($dte, "U"));
        }
    }
    
    static public function roundTime($tme) {
        return(round($tme/self::TW_STEP)*self::TW_STEP);
    }
    
    static function zabbixGraphUrl1($itemids, $start, $seconds) {
        if ($itemids) {
            $itemidsstr = "";
            foreach ($itemids as $i) {
                $itemidsstr.="itemids[$i]=$i&";
            }
        } else {
            $itemidsstr = "";
        }
        $url=sprintf("%s/history.php?", Opts::getOpt("zaburl")) . sprintf("action=batchgraph&%s&graphtype=0&period=%d&stime=%d", $itemidsstr, $seconds, $start);
        return($url);
    }
    
    static function zabbixGraphUrl2($itemids, $start, $seconds) {
        if ($itemids) {
            $itemidsstr = "";
            $j=0;
            foreach ($itemids as $i) {
                $itemidsstr.="itemids[$j]=$i&";
                $j++;
            }
        } else {
            $itemidsstr = "";
        }
        $url=sprintf("%s/chart.php?", Opts::getOpt("zaburl")) . sprintf("period=%d&stime=%s&%s&type=0&batch=1&updateProfile=0&profileIdx=&profileIdx2=&width=1024", $seconds, date("YmdHis",$start), $itemidsstr);
        return($url);
    }

}

?>
