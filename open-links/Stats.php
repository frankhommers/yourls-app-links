<?php
if (!defined('YOURLS_ABSPATH')) exit;

/** Count only the existing, explicitly identified native YOURLS row. */
function hmm_open_count(string $keyword): void {
    try {
        yourls_update_clicks($keyword);
        yourls_log_redirect($keyword);
    } catch (\Exception $e) {
        yourls_do_action('hmm_open_stats_error',$e);
    }
}
