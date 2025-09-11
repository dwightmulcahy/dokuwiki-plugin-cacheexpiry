<?php
if (!defined('DOKU_INC')) die();

class action_plugin_cacheexpiry extends DokuWiki_Action_Plugin {

    public function register(Doku_Event_Handler $controller) {
        // cache control only (syntax handles inline rendering)
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'set_expiry');
    }

    public function set_expiry(Doku_Event $event) {
        global $conf;
        if ($event->data->mode !== 'xhtml') return;
        $id = $event->data->page; if (empty($id)) return;

        /** @var helper_plugin_cacheexpiry $H */
        $H = plugin_load('helper','cacheexpiry');
        if (!$H) return;

        $tzName = !empty($conf['timezone']) ? $conf['timezone'] : date_default_timezone_get();
        try { $tz = new DateTimeZone($tzName); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }
        $weekStart = (int)$this->getConf('week_start'); if ($weekStart < 0 || $weekStart > 6) $weekStart = 1;
        $minCache = (int)$this->getConf('min_cache_seconds'); if ($minCache < 1) $minCache = 60;

        $source = null; $markersFound = array();
        $secondsUntil = $H->computeForPage($id, $tz, $weekStart, $source, $markersFound);
        if ($secondsUntil === null) { $H->log_debug('cacheexpiry: no rules for page=' . $id . ' (no markers, no namespace defaults)'); return; }
        if ($secondsUntil < $minCache) $secondsUntil = $minCache;

        $event->data->depends['age'] = $secondsUntil;

        $now = new DateTime('now', $tz);
        $expiresAt = (clone $now)->modify('+' . $secondsUntil . ' seconds');
        $H->log_debug(sprintf(
            'cacheexpiry: page=%s tz=%s weekly_mode=%s source=%s markers=%s seconds=%d expires_at=%s',
            $id, $tz->getName(),
            $this->getConf('weekly_same_weekday') ? 'same_weekday' : 'week_start(' . $weekStart . ')',
            $source, implode(',', $markersFound), $secondsUntil, $expiresAt->format('Y-m-d H:i:s')
        ));
    }
}
