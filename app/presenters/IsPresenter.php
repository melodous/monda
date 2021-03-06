<?php

namespace App\Presenters;

use App\Model\ItemStat,
    Tracy\Debugger,
    App\Model\Opts,
    App\Model\CliDebug,
    App\Model\Monda,
    App\Model\Util,
    App\Model\Triggerinfo,
    App\Model\Tw,
    Nette\Utils\DateTime as DateTime;

class IsPresenter extends BasePresenter {

    public function Help() {
        CliDebug::warn("
     ItemStats operations
            
     is:show [common opts]
     is:stats [common opts]
     is:history [common opts]
     is:compute [common opts]
     is:delete [common opts]
     is:loi [common opts]

     [common opts]
    \n");
        Opts::helpOpts();
        Opts::showOpts();
        echo "\n";
        self::mexit();
    }

    public function startup() {
        parent::startup();
        HsPresenter::startup();
        
        Opts::addOpt(
                false, "min_values_per_window", "Minimum values for item per window to process", 20, 20
        );
        Opts::addOpt(
                false, "min_avg_for_cv", "Minimum average for CV to process", 0.01, 0.01
        );
        Opts::addOpt(
                false, "min_stddev", "Minimum stddev of values to process. Only bigger stddev will be processed", 0, 0
        );
        Opts::addOpt(
                false, "min_cv", "Minimum CV to process values.", 0.01, 0.01
        );
        Opts::addOpt(
                false, "max_cv", "Maximum CV to process values.", 100, 100
        );
        Opts::addOpt(
                false, "is_minloi", "Minimum itemstat loi to search.", 0, 0
        );
        Opts::addOpt(
                false, "itemids", "Itemids to get", false, "All"
        );
        Opts::addOpt(
                false, "history_granularity", "Granularity of history data to fetch in seconds.", 600, 600
        );
        Opts::addOpt(
                false, "history_interpolate", "Interpolate history data (slow).", true, "yes"
        );
        Opts::addOpt(
                false, "triggerids", "Select this triggerids to history", (int) 0, 0
        );
        Opts::addOpt(
                false, "events_prefetch", "Prefetch this number of seconds before history dump", Monda::_1WEEK, "1 week"
        );
        Opts::addOpt(
                false, "max_windows_per_query", "Maximum number of windows per one sql query", 10, 10
        );
        Opts::addOpt(
                false, "items", "Item keys to get. Use ~ to add more items. Prepend item by @ to use regex.", false, "All"
        );
        Opts::addOpt(
                false, "anonymize_items", "Anonymize item names", false, "no"
        );
        Opts::addOpt(
                false, "item_restricted_chars", "Characters mangled in items", false, "none"
        );
        Opts::addOpt(
                false, "wevent_problem_treshold", "Ratio for timewindow if there are more events when to classify as problem", 0.02, 0.02
        );
        Opts::addOpt(
                false, "event_value_filter", "Filter trigger history to only this values {OK|PROBLEM|50}. 50 means distribute to 50%.", false, false,Array("OK","PROBLEM","50")
        );
        
        Opts::setDefaults();
        Opts::readCfg(Array("Is"));
        Opts::readOpts($this->params);
        self::postCfg();
        if ($this->action=="stats") {
            if (Opts::isDefault("brief_columns")) {
                Opts::setOpt("brief_columns",Array("itemid","avg_","loi","wcnt"));
            }
        } else {
            if (Opts::isDefault("brief_columns")) {
                Opts::setOpt("brief_columns",Array("itemid","stddev_","cv","loi"));
            }
        }
    }

    static function postCfg() {
        HsPresenter::postCfg();
        Opts::optToArray("itemids");
        Opts::optToArray("items", "~");
        Opts::optToArray("triggerids", ",");
        if (!Opts::isOpt("itemids")) {
            if (Opts::getOpt("triggerids")) {
                $itemids = TriggerInfo::Triggers2Items(Opts::getOpt("triggerids"));
                if (count($itemids) == 0) {
                    self::mexit(2, "No itemids for triggerids found.");
                }
                Opts::setOpt("itemids", $itemids);
            }
        }
        if (Opts::getOpt("output_mode")=="arff") {
            Opts::setOpt("item_restricted_chars","{}[],.| ");
        }
        if (Opts::getOpt("events_prefetch")) {
            if (Util::timetoseconds(Opts::getOpt("events_prefetch")) - time()>0) {
                Opts::setOpt("events_prefetch", Util::timetoseconds(Opts::getOpt("events_prefetch")) - time());
            } else {
                Opts::setOpt("events_prefetch", Util::timetoseconds(Opts::getOpt("events_prefetch")));
            }
        }
        if (!is_array(Opts::getOpt("itemids"))) {
            ItemStat::itemsToIds();
        }
        if (!Opts::getOpt("anonymize_key") && Opts::getOpt("anonymize_items")) {
            self::mexit(2,"You must use --anonymize_key to anonymize items.");
        }
    }
    
    static function expandItemParams($item) {
        if (preg_match("/\[(.*)\]/",$item[0]->key_,$params)) {
            $params=preg_split("/,/",$params[1]);
            foreach ($params as $i=>$p) {
                $item[0]->name=str_replace('$'.($i+1),$p,$item[0]->name);
            }
        }
        return($item);
    }

    static function expandItem($itemid, $withhost = false, $desc = false) {
        $ii = ItemStat::itemInfo($itemid);
        $ii=self::expandItemParams($ii);
        if (count($ii) > 0) {
            if ($desc) {
                $itxt = $ii[0]->name;
            } else {
                $itxt = $ii[0]->key_;
            }
            if (Opts::getOpt("anonymize_items")) {
                $itxt=Util::anonymize($itxt,Opts::getOpt("anonymize_key"));
            } else {
                if (Opts::getOpt("item_restricted_chars")) {
                    $itxt=strtr($itxt,Opts::getOpt("item_restricted_chars"),"_____________");
                }
            }
            if ($withhost) {
                return(HsPresenter::expandHost($ii[0]->hostid) . ":" . $itxt);
            } else {
                return($itxt);
            }
        } else {
            return("unknown");
        }
    }

    public function renderShow() {
        $rows = ItemStat::isSearch();
        if ($rows && $rows->getRowCount()>0) {
            $this->exportdata = $rows->fetchAll();
            if (Opts::getOpt("output_verbosity") == "expanded") {
                foreach ($this->exportdata as $i => $row) {
                    CliDebug::dbg(sprintf("Processing %d row of %d          \r", $i, count($this->exportdata)));
                    $row["host"] = HsPresenter::expandHost($row->hostid);
                    $row["key"] = self::expandItem($row->itemid);
                    $this->exportdata[$i] = $row;
                }
            }
            parent::renderShow($this->exportdata);
        } else {
            self::helpEmpty();
        }
        self::mexit();
    }
    
    public function renderHistory() {
        if (!Opts::getOpt("itemids")) {
            self::mexit("You must use --items parameter to select items!\n");
        }
        if (Opts::getOpt("output_mode") == "brief") {
            self::mexit(3, "This action is possible only with csv output mode.\n");
        }
        Opts::setOpt("tw_sort","start/+");
        $rows = ItemStat::isZabbixHistory();
        if ($rows) {
            $clocks = Array();
            $this->exportdata = $rows;
            $i = 0;
            foreach ($this->exportdata as $clock => $row) {
                $i++;
                $clocks[] = $row["clock"];
                CliDebug::dbg(sprintf("Processing %d row of %d      \r", $i, count($this->exportdata)));
                foreach ($row as $column => $value) {
                    if (!array_key_exists($column, $this->exportinfo)) {
                        if ($column == "clock") {
                            $this->exportinfo[$column] = "clock";
                        } else {
                            $this->exportinfo[$column] = self::expandItem($column, true);
                        }
                        $this->arffinfo[$column] = "NUMERIC";
                    }
                }
            }
            $trows = TriggerInfo::History(Opts::getOpt("triggerids"), $clocks);
            foreach (Opts::getOpt("triggerids") as $t) {
                $this->exportinfo[$t] = TriggerInfo::expandTrigger($t);
                $this->arffinfo[$t] = "{OK,PROBLEM}";
                foreach ($trows as $i => $trow) {
                    $value=$trow[$t];
                    if (Opts::getOpt("event_value_filter")) {
                        if (Opts::getOpt("event_value_filter") == "50") {
                            if ($valuesproblem > $valuesok && $value == "OK") {
                                $valuesok++;
                            } elseif ($value == "PROBLEM") {
                                $valuesproblem++;
                            } else {
                                CliDebug::info(sprintf("Skipping clock %d due to 50/50 filter\n", $i));
                                unset($this->exportdata[$i]);
                                continue;
                            }
                        } elseif ($value != Opts::getOpt("event_value_filter")) {
                            unset($this->exportdata[$i]);
                            continue;
                        }
                    }
                    $this->exportdata[$i][$t] = $trow[$t];
                }
            }
        }

        //$this->exportdata=  array_merge_recursive($trows,$this->exportdata);
        parent::renderShow($this->exportdata);
    }

    public function renderStats() {
        $rows = ItemStat::isStats();
        if ($rows) {
            $this->exportdata = $rows;
            if (Opts::getOpt("output_verbosity") == "expanded") {
                foreach ($this->exportdata as $i => $row) {
                    CliDebug::dbg(sprintf("Processing %d row of %d          \r", $i, count($this->exportdata)));
                    $row["key"] = self::expandItem($row->itemid, true);
                    $this->exportdata[$i] = $row;
                }
            }
            parent::renderShow($this->exportdata);
        }
        self::mexit();
    }

    public function renderLoi() {
        ItemStat::IsLoi();
        self::mexit();
    }

    public function renderCompute() {
        ItemStat::IsMultiCompute();
        Tw::twLoi();
        self::mexit(0, "Done\n");
    }

    public function renderDelete() {
        ItemStat::IsDelete();
        self::mexit(0, "Done\n");
    }

}
