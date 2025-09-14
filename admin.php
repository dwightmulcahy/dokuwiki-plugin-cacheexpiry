<?php
if (!defined('DOKU_INC')) die();

require_once(DOKU_INC . 'inc/search.php');

class admin_plugin_cacheexpiry extends DokuWiki_Admin_Plugin {
    protected $nsFilter = '';
    protected $ruleFilter = '';
    protected $expWithin = 0; // minutes
    protected $page = 1;
    protected $perPage = 100;
    protected $bulkMessage = '';
    protected $bulkPurgeLinks = array();

    public function getMenuSort() { return 850; }
    public function forAdminOnly() { return true; }
    public function getMenuText($language) { return $this->getLang('menu'); }

    public function handle() {
        // Filters (GET)
        $this->nsFilter = trim((string)($_GET['ns'] ?? ''));
        $this->ruleFilter = trim((string)($_GET['rule'] ?? ''));
        $this->expWithin = (int)($_GET['exp'] ?? 0);
        $this->page = max(1, (int)($_GET['p'] ?? 1));

        // Bulk actions (POST)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cacheexpiry_bulk'])) {
            if (!checkSecurityToken()) return;
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_filter(array_map('trim', $_POST['ids'])) : array();
            $action = trim((string)($_POST['bulk_action'] ?? ''));
            if (!empty($ids) && $action !== '') {
                if ($action === 'recompute') {
                    $cnt = $this->bulkRecompute($ids);
                    $this->bulkMessage = sprintf($this->getLang('bulk_done'), $cnt);
                } elseif ($action === 'purge') {
                    $this->bulkPurgeLinks = $this->makePurgeLinks($ids);
                }
            }
        }
    }

    protected function bulkRecompute($ids) {
        global $conf;
        /** @var helper_plugin_cacheexpiry $H */
        $H = plugin_load('helper','cacheexpiry');
        $tzName = !empty($conf['timezone']) ? $conf['timezone'] : date_default_timezone_get();
        try { $tz = new DateTimeZone($tzName); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }
        $weekStart = (int)$this->getConf('week_start'); if ($weekStart < 0 || $weekStart > 6) $weekStart = 1;

        $count = 0;
        foreach ($ids as $id) {
            $source = null; $markers = array();
            $sec = $H->computeForPage($id, $tz, $weekStart, $source, $markers);
            $minCache = (int)$this->getConf('min_cache_seconds'); if ($minCache < 1) $minCache = 60;
            if ($sec !== null && $sec < $minCache) $sec = $minCache;

            $now = new DateTime('now', $tz);
            $next = $sec === null ? null : (clone $now)->modify('+' . $sec . ' seconds')->getTimestamp();
            $meta = array(
                'rule' => $this->deriveRuleLabel($markers, $source),
                'source' => $source ?: 'none',
                'markers' => $markers,
                'next_expires_at' => $next,
                'computed_at' => time(),
                'tz' => $tz->getName(),
                'weekly_mode' => $this->getConf('weekly_same_weekday') ? 'same_weekday' : ('week_start(' . $weekStart . ')'),
            );
            p_set_metadata($id, array('plugin' => array('cacheexpiry' => $meta)), false, true);
            $count++;
        }
        return $count;
    }

    protected function makePurgeLinks($ids) {
        $links = array();
        foreach ($ids as $id) {
            $links[$id] = wl($id, array('purge'=>'true'));
        }
        return $links;
    }

    public function html() {
        global $conf;
        /** @var helper_plugin_cacheexpiry $H */
        $H = plugin_load('helper','cacheexpiry');
        if (!$H) { echo '<p>Helper missing.</p>'; return; }

        $tzName = !empty($conf['timezone']) ? $conf['timezone'] : date_default_timezone_get();
        try { $tz = new DateTimeZone($tzName); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }
        $weekStart = (int)$this->getConf('week_start'); if ($weekStart < 0 || $weekStart > 6) $weekStart = 1;

        echo '<h1>'.$this->getLang('overview_title').'</h1>';

        if ($this->bulkMessage) {
            echo '<div class="success">'.$this->bulkMessage.'</div>';
        }
        if (!empty($this->bulkPurgeLinks)) {
            echo '<div class="info"><p>'.$this->getLang('bulk_purge_list').'</p><ul>';
            foreach ($this->bulkPurgeLinks as $id=>$url) {
                echo '<li>'.html_wikilink($id).' — <a href="'.hsc($url).'" target="_blank">'.hsc($url).'</a></li>';
            }
            echo '</ul></div>';
        }

        // Filters
        echo '<form method="get" action="">';
        echo '<input type="hidden" name="do" value="admin" />';
        echo '<input type="hidden" name="page" value="cacheexpiry" />';
        echo '<div class="cacheexpiry-filters" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">';
        echo '<label>'.$this->getLang('filter_ns').' ';
        echo '<input type="text" name="ns" value="'.hsc($this->nsFilter).'" placeholder="e.g. journal:" />';
        echo '</label>';
        echo '<label>'.$this->getLang('filter_rule').' ';
        echo '<select name="rule">';
        $opts = array('', 'REFRESH-HOURLY','REFRESH-DAILY','REFRESH-WEEKLY','REFRESH-MINUTES');
        foreach ($opts as $o) {
            $sel = ($o === $this->ruleFilter) ? ' selected' : '';
            $label = $o === '' ? $this->getLang('none') : $o;
            echo '<option value="'.hsc($o).'"'.$sel.'>'.hsc($label).'</option>';
        }
        echo '</select></label>';
        echo '<label>'.$this->getLang('filter_expwin').' ';
        echo '<input type="number" min="0" name="exp" value="'.hsc($this->expWithin).'" style="width:7em" />';
        echo '</label>';
        echo '<button class="button">'.$this->getLang('filter_btn').'</button>';
        echo '</div>';
        echo '</form>';

        // Collect pages
        $data = array();
        search($data, $conf['datadir'], 'search_allpages', array('skipacl' => false));
        $rows = array();
        $now = new DateTime('now', $tz);
        $fmt = $this->getConf('show_next_refresh_format') ?: 'Y-m-d H:i T';

        foreach ($data as $item) {
            $id = $item['id'];
            if ($this->nsFilter !== '' && strpos($id, $this->nsFilter) !== 0) continue;

            $source = null; $markers = array();
            $sec = $H->computeForPage($id, $tz, $weekStart, $source, $markers);
            $rule = $this->deriveRuleLabel($markers, $source);
            if ($this->ruleFilter !== '' && !$this->ruleMatches($rule, $markers, $this->ruleFilter)) continue;

            if ($sec === null) {
                $nextStr = $this->getLang('none'); $remainStr = $this->getLang('none'); $remain = PHP_INT_MAX; $expiryTs=null;
            } else {
                $minCache = (int)$this->getConf('min_cache_seconds'); if ($minCache < 1) $minCache = 60;
                $clamped = max($minCache, $sec);
                $next = (clone $now)->modify('+' . $clamped . ' seconds');
                $nextStr = hsc($next->format($fmt));
                $remain = $clamped;
                $remainStr = $this->formatDuration($clamped);
                $expiryTs = $next->getTimestamp();
            }

            if ($this->expWithin > 0 && $sec !== null && $sec/60 > $this->expWithin) continue;

            // Existing raw metadata (if any)
            $raw = p_get_metadata($id, 'plugin cacheexpiry');

            $rows[] = array(
                'id' => $id,
                'rule' => $rule,
                'source' => $source ?: $this->getLang('none'),
                'markers' => $markers,
                'next' => $nextStr,
                'remain' => $remain,
                'remain_str' => $remainStr,
                'expiry_ts' => $expiryTs,
                'weeklymode' => $this->getConf('weekly_same_weekday') ? 'same_weekday' : ('week_start(' . $weekStart . ')'),
                'tz' => $tz->getName(),
                'raw' => $raw,
                'now_ts' => $now->getTimestamp(),
                'sec_raw' => $sec,
                'min_cache' => (int)$this->getConf('min_cache_seconds')
            );
        }

        // Sort by next expiry (remaining asc)
        usort($rows, function($a,$b){
            return $a['remain'] <=> $b['remain'];
        });

        $total = count($rows);
        $start = ($this->page - 1) * $this->perPage;
        $slice = array_slice($rows, $start, $this->perPage);

        if (empty($slice)) {
            echo '<p>'.$this->getLang('no_results').'</p>';
            return;
        }

        // Bulk form wrapper
        echo '<form method="post">';
        formSecurityToken();
        echo '<input type="hidden" name="cacheexpiry_bulk" value="1" />';

        // Bulk toolbar
        echo '<div style="margin:10px 0; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">';
        echo '<strong>'.$this->getLang('bulk').':</strong>';
        echo '<button type="button" class="button" onclick="cacheexp_selectAll(true)">'.$this->getLang('bulk_select_all').'</button>';
        echo '<button type="button" class="button" onclick="cacheexp_selectAll(false)">'.$this->getLang('bulk_none').'</button>';
        echo '<label>'.$this->getLang('bulk_action').' ';
        echo '<select name="bulk_action">';
        echo '<option value="">--</option>';
        echo '<option value="recompute">'.$this->getLang('bulk_recompute').'</option>';
        echo '<option value="purge">'.$this->getLang('bulk_purge').'</option>';
        echo '</select></label>';
        echo '<button class="button">'.$this->getLang('bulk_do').'</button>';
        echo '</div>';

        // Table
        echo '<div class="table"><table class="inline">';
        echo '<thead><tr>';
        $cols = array('','table_page','table_rule','table_source','table_markers','table_next','table_remaining','table_weeklymode','table_timezone','table_actions');
        foreach ($cols as $idx=>$c) {
            if ($idx===0) { echo '<th style="width:26px;"><input type="checkbox" onclick="cacheexp_toggleHeader(this)"/></th>'; }
            else { echo '<th>'.$this->getLang($c).'</th>'; }
        }
        echo '</tr></thead><tbody>';

        foreach ($slice as $i=>$r) {
            $rowId = 'ce_row_' . $i;
            $insId = 'ce_ins_' . $i;
            echo '<tr id="'.$rowId.'">';
            echo '<td><input type="checkbox" class="cebox" name="ids[]" value="'.hsc($r['id']).'"/></td>';
            echo '<td>'.html_wikilink($r['id']).'</td>';
            echo '<td>'.hsc($r['rule']).'</td>';
            echo '<td>'.hsc($this->humanSource($r['source'])).'</td>';
            echo '<td>'.($r['markers'] ? hsc(implode(',', $r['markers'])) : $this->getLang('none')).'</td>';
            echo '<td>'.$r['next'].'</td>';
            echo '<td>'.hsc($r['remain_str']).'</td>';
            echo '<td>'.hsc($r['weeklymode']).'</td>';
            echo '<td>'.hsc($r['tz']).'</td>';
            // actions
            $purge = wl($r['id'], array('purge' => 'true'));
            $inspectBtn = '<button class="button" type="button" onclick="cacheexp_toggle(''.$insId.'')">'.$this->getLang('inspect').'</button>';
            $recomputeBtn = '<button class="button" type="submit" name="single_recompute" value="'.hsc($r['id']).'">'.$this->getLang('recompute').'</button>';
            echo '<td>';
            echo '<a class="button" href="'.hsc(wl($r['id'])).'">'.$this->getLang('open').'</a> ';
            echo '<a class="button" href="'.hsc($purge).'" target="_blank">'.$this->getLang('purge').'</a> ';
            echo $inspectBtn.' ';
            echo $recomputeBtn;
            echo '</td>';
            echo '</tr>';

            // Inspector row
            echo '<tr id="'.$insId.'" style="display:none;">';
            echo '<td></td><td colspan="9">';
            echo '<div class="ce-inspector" style="border:1px solid #ddd;padding:10px;border-radius:6px;background:#fafafa;">';
            echo '<strong>'.$this->getLang('inspect').':</strong> '.hsc($r['id']).'<br/>';
            echo '<ul style="margin:8px 0 12px 18px;">';
            echo '<li>'.$this->getLang('calc_rule').': <code>'.hsc($r['rule']).'</code></li>';
            echo '<li>'.$this->getLang('calc_source').': <code>'.hsc($r['source']).'</code></li>';
            echo '<li>'.$this->getLang('calc_markers').': <code>'.($r['markers']?hsc(implode(",", $r['markers'])):$this->getLang('none')).'</code></li>';
            echo '<li>'.$this->getLang('calc_now').': <code>'.hsc(date($fmt, $r['now_ts'])).' ('.$r['now_ts'].')</code></li>';
            $secRaw = ($r['sec_raw']===null)?'null':$r['sec_raw'];
            echo '<li>'.$this->getLang('calc_seconds').': <code>'.hsc($secRaw).'</code></li>';
            echo '<li>'.$this->getLang('calc_minclamp').': <code>'.hsc($r['min_cache']).'</code></li>';
            $exp = $r['expiry_ts'] ? date($fmt, $r['expiry_ts']) . ' ('.$r['expiry_ts'].')' : $this->getLang('none');
            echo '<li>'.$this->getLang('calc_expiry').': <code>'.hsc($exp).'</code></li>';
            echo '</ul>';

            // Raw metadata box
            $rawpretty = htmlspecialchars(json_encode($r['raw'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
            echo '<details><summary>'.$this->getLang('meta_raw').'</summary>';
            echo '<pre style="max-height:240px;overflow:auto;background:#fff;border:1px solid #eee;padding:8px;border-radius:4px;">'.$rawpretty.'</pre>';
            echo '</details>';

            echo '</div>';
            echo '</td></tr>';
        }

        echo '</tbody></table></div>';

        // Bulk form footer
        echo '<div style="margin-top:10px;">';
        echo '<button class="button">'.$this->getLang('bulk_do').'</button>';
        echo '</div>';
        echo '</form>';

        // Simple pager
        $pages = (int)ceil($total / $this->perPage);
        if ($pages > 1) {
            echo '<div class="paging" style="margin-top:10px;">';
            for ($p=1; $p <= $pages; $p++) {
                $qs = array('do'=>'admin','page'=>'cacheexpiry','ns'=>$this->nsFilter,'rule'=>$this->ruleFilter,'exp'=>$this->expWithin,'p'=>$p);
                $url = wl('', $qs, true, '&');
                $cls = $p == $this->page ? ' class="cur"' : '';
                echo '<a'.$cls.' href="'.hsc($url).'">'.$p.'</a> ';
            }
            echo '</div>';
        }

        // JS helpers
        echo '<script>
function cacheexp_toggle(id){ var e=document.getElementById(id); if(!e) return; e.style.display=(e.style.display==="none"||e.style.display==="")?"table-row":"none"; }
function cacheexp_selectAll(on){ var boxes=document.querySelectorAll(".cebox"); boxes.forEach(function(b){ b.checked=!!on; }); }
function cacheexp_toggleHeader(h){ cacheexp_selectAll(h.checked); }
</script>';
    }

    protected function ruleMatches($ruleLabel, $markers, $filter) {
        if ($filter === '') return true;
        if ($filter === 'REFRESH-MINUTES') {
            foreach ($markers as $m) if (strpos($m, 'REFRESH-MINUTES(') === 0) return true;
            return false;
        }
        if ($ruleLabel === $filter) return true;
        foreach ($markers as $m) if ($m === $filter) return true;
        return false;
    }

    protected function humanSource($src) {
        if ($src === 'namespace(hourly)') return $this->getLang('src_ns_hourly');
        if ($src === 'namespace(daily)')  return $this->getLang('src_ns_daily');
        if ($src === 'namespace(weekly)') return $this->getLang('src_ns_weekly');
        if ($src === 'wikitext')          return $this->getLang('src_wikitext');
        if (!$src || $src === 'none')     return $this->getLang('none');
        return $src;
    }

    protected function deriveRuleLabel($markers, $source) {
        if (empty($markers)) {
            if ($source === 'namespace(hourly)') return 'REFRESH-HOURLY';
            if ($source === 'namespace(daily)')  return 'REFRESH-DAILY';
            if ($source === 'namespace(weekly)') return 'REFRESH-WEEKLY';
            return $this->getLang('none');
        }
        if (count($markers) === 1) return $markers[0];
        $mins = array();
        foreach ($markers as $m) if (preg_match('/^REFRESH-MINUTES\((\d{1,4})\)$/', $m, $mm)) $mins[] = (int)$mm[1];
        if (!empty($mins)) return 'REFRESH-MINUTES(' . min($mins) . ')';
        if (in_array('REFRESH-HOURLY', $markers, true)) return 'REFRESH-HOURLY';
        if (in_array('REFRESH-DAILY',  $markers, true)) return 'REFRESH-DAILY';
        if (in_array('REFRESH-WEEKLY', $markers, true)) return 'REFRESH-WEEKLY';
        return implode(',', $markers);
    }

    protected function formatDuration($sec) {
        if ($sec === PHP_INT_MAX || $sec === null) return $this->getLang('none');
        $sec = max(0, (int)$sec);
        $h = floor($sec / 3600); $m = floor(($sec % 3600) / 60); $s = $sec % 60;
        if ($h > 0) return sprintf('%02dh %02dm %02ds', $h, $m, $s);
        if ($m > 0) return sprintf('%02dm %02ds', $m, $s);
        return sprintf('%02ds', $s);
    }
}
