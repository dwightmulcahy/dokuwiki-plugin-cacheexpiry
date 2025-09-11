<?php
if (!defined('DOKU_INC')) die();

class helper_plugin_cacheexpiry extends DokuWiki_Plugin {

    public function log_debug($msg) {
        if (!$this->getConf('enable_debug_log')) {
            return;
        }
        if (function_exists('dbglog')) { dbglog($msg); } else { error_log($msg); }
    }

    public function secondsUntilNextBoundary($mode, DateTimeZone $tz, $weekStart, $nMinutes = null) {
        $now = new DateTime('now', $tz);

        if ($mode === 'hourly') {
            $next = (clone $now)->setTime((int)$now->format('H'), 0, 0)->modify('+1 hour');
        } elseif ($mode === 'daily') {
            $next = (clone $now)->setTime(0, 0, 0)->modify('+1 day');
        } elseif ($mode === 'weekly') {
            $dow = (int)$now->format('w');
            if ($this->getConf('weekly_same_weekday')) {
                $next = (clone $now)->setTime(0,0,0)->modify('+1 week');
                $curW = (int)$next->format('w');
                $shift = ($dow - $curW + 7) % 7;
                $next = $next->modify('+' . $shift . ' day');
            } else {
                $daysAhead = ($weekStart - $dow + 7) % 7;
                if ($daysAhead === 0) {
                    $midnight = (clone $now)->setTime(0,0,0);
                    if ($now > $midnight) {
                        $daysAhead = 7;
                    }
                }
                $next = (clone $now)->setTime(0,0,0)->modify('+' . $daysAhead . ' day');
            }
        } else { // minutes
            $n = max(1, min(1440, (int)$nMinutes));
            $cur = (int)$now->format('i');
            $t = (clone $now)->setTime((int)$now->format('H'), $cur - ($cur % $n), 0);
            $next = $t->modify('+' . $n . ' minutes');
            if ($next <= $now) {
                $next = $next->modify('+' . $n . ' minutes');
            }
        }

        return max(1, $next->getTimestamp() - $now->getTimestamp());
    }

    public function parseMarkersFromText($text) {
        $found = array();
        if (strpos($text, '~~REFRESH-HOURLY~~') !== false) {
            $found[] = 'REFRESH-HOURLY';
        }
        if (strpos($text, '~~REFRESH-DAILY~~') !== false) {
            $found[] = 'REFRESH-DAILY';
        }
        if (strpos($text, '~~REFRESH-WEEKLY~~') !== false) {
            $found[] = 'REFRESH-WEEKLY';
        }
        if (preg_match_all('/~~REFRESH-MINUTES\((\d{1,4})\)~~/i', $text, $m)) {
            foreach ($m[1] as $n) {
                $found[] = 'REFRESH-MINUTES(' . (int)$n . ')';
            }
        }
        return $found;
    }

    public function bestSecondsForMarkers($markers, DateTimeZone $tz, $weekStart) {
        $candidates = array();
        foreach ($markers as $mk) {
            if ($mk === 'REFRESH-HOURLY') {
                $candidates[] = $this->secondsUntilNextBoundary('hourly', $tz, $weekStart);
            } elseif ($mk === 'REFRESH-DAILY') {
                $candidates[] = $this->secondsUntilNextBoundary('daily', $tz, $weekStart);
            } elseif ($mk === 'REFRESH-WEEKLY') {
                $candidates[] = $this->secondsUntilNextBoundary('weekly', $tz, $weekStart);
            } elseif (preg_match('/^REFRESH-MINUTES\((\d{1,4})\)$/', $mk, $mm)) {
                $n = (int)$mm[1];
                $candidates[] = $this->secondsUntilNextBoundary('minutes', $tz, $weekStart, $n);
            }
        }
        if (empty($candidates)) {
            return null;
        }
        sort($candidates, SORT_NUMERIC);
        return $candidates[0];
    }

    public function computeForPage($id, DateTimeZone $tz, $weekStart, &$source = null, &$markersFound = array()) {
        $text = rawWiki($id);
        if ($text !== null) {
            $markersFound = $this->parseMarkersFromText($text);
            p_set_metadata($id, array('plugin' => array('cacheexpiry' => array('markers' => $markersFound))), false, true);
            if (!empty($markersFound)) {
                $source = 'wikitext';
                $sec = $this->bestSecondsForMarkers($markersFound, $tz, $weekStart);
                if ($sec !== null) {
                    return $sec;
                }
            } else {
                p_set_metadata($id, array('plugin' => array('cacheexpiry' => array('markers' => array()))), false, true);
            }
        }

        $inNs = function($id, $csv) {
            if (!$csv) {
                return false;
            }
            $parts = preg_split('/\s*,\s*/', $csv, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $ns) {
                if ($ns !== '' && substr($ns, -1) !== ':') {
                    $ns .= ':';
                }
                if ($ns !== ':' && strpos($id, $ns) === 0) {
                    return true;
                }
            }
            return false;
        };

        if ($inNs($id, $this->getConf('defaults_hourly_ns'))) {
            $markersFound = array('REFRESH-HOURLY');
            $source = 'namespace(hourly)';
            return $this->secondsUntilNextBoundary('hourly', $tz, $weekStart);
        } elseif ($inNs($id, $this->getConf('defaults_daily_ns'))) {
            $markersFound = array('REFRESH-DAILY');
            $source = 'namespace(daily)';
            return $this->secondsUntilNextBoundary('daily', $tz, $weekStart);
        } elseif ($inNs($id, $this->getConf('defaults_weekly_ns'))) {
            $markersFound = array('REFRESH-WEEKLY');
            $source = 'namespace(weekly)';
            return $this->secondsUntilNextBoundary('weekly', $tz, $weekStart);
        }

        $markersFound = array();
        $source = 'none';
        return null;
    }
}
