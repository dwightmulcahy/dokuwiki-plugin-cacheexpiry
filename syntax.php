<?php
if (!defined('DOKU_INC')) die();

class syntax_plugin_cacheexpiry extends DokuWiki_Syntax_Plugin {

    public function getType() { return 'substition'; }
    public function getSort() { return 20; } // match before generic ~~del~~
    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('~~REFRESH-(?:HOURLY|DAILY|WEEKLY|MINUTES\(\d{1,4}\))~~', $mode, 'plugin_cacheexpiry');
        // legacy ~~DAILY~~ removed
    }

    public function handle($match, $state, $pos, Doku_Handler $handler) {
        $inner = substr($match, 2, -2);
        $mode = 'unknown'; $n = null;
        if (preg_match('/^REFRESH-MINUTES\((\d{1,4})\)$/i', $inner, $m)) { $mode='minutes'; $n=(int)$m[1]; }
        elseif (strcasecmp($inner, 'REFRESH-HOURLY') === 0) { $mode='hourly'; }
        elseif (strcasecmp($inner, 'REFRESH-DAILY') === 0)  { $mode='daily'; }
        elseif (strcasecmp($inner, 'REFRESH-WEEKLY') === 0) { $mode='weekly'; }
        return array($mode, $n);
    }

    public function render($format, Doku_Renderer $R, $data) {
        if ($format !== 'xhtml') return false;
        list($mode, $n) = $data;
        if (!$this->getConf('show_next_refresh')) return true;

        global $ID, $conf;
        /** @var helper_plugin_cacheexpiry $H */
        $H = plugin_load('helper','cacheexpiry');
        if (!$H) return false;

        $tzName = !empty($conf['timezone']) ? $conf['timezone'] : date_default_timezone_get();
        try { $tz = new DateTimeZone($tzName); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }
        $weekStart = (int)$this->getConf('week_start'); if ($weekStart < 0 || $weekStart > 6) $weekStart = 1;

        if ($mode === 'unknown') return true;
        if ($mode === 'minutes') {
            $sec = $H->secondsUntilNextBoundary('minutes', $tz, $weekStart, $n);
        } else {
            $sec = $H->secondsUntilNextBoundary($mode, $tz, $weekStart);
        }

        $minCache = (int)$this->getConf('min_cache_seconds'); if ($minCache < 1) $minCache = 60;
        if ($sec < $minCache) $sec = $minCache;

        $now = new DateTime('now', $tz);
        $expiry = (clone $now)->modify('+' . $sec . ' seconds');
        $formatStr = $this->getConf('show_next_refresh_format') ?: 'Y-m-d H:i T';
        $template = $this->getConf('show_next_refresh_template') ?: 'Next refresh: %s';
        $stamp = $expiry->format($formatStr);
        $html = '<span class="cacheexpiry-nextrefresh">' . hsc(sprintf($template, $stamp)) . '</span>';
        $R->doc .= $html;

        if ($this->getConf('enable_debug_log')) {
            $H->log_debug('cacheexpiry: syntax rendered inline timestamp for ' . $mode . ($mode==='minutes'?'(' . $n . ')':'') . ' on page=' . $ID);
        }

        return true;
    }
}
