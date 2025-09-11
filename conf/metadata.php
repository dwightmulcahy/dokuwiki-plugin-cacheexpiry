<?php
$meta['defaults_hourly_ns'] = array('string');
$meta['defaults_daily_ns']  = array('string');
$meta['defaults_weekly_ns'] = array('string');
$meta['week_start'] = array('multichoice', '_choices' => array(0,1,2,3,4,5,6));
$meta['min_cache_seconds'] = array('numeric');
$meta['weekly_same_weekday'] = array('onoff');
$meta['show_next_refresh'] = array('onoff');
$meta['show_next_refresh_template'] = array('string');
$meta['show_next_refresh_format'] = array('string');
$meta['enable_debug_log'] = array('onoff');
