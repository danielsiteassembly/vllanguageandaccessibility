<?php
if ( ! defined('ABSPATH') ) exit;

class VL_LAS_DB {
    const TABLE = 'vl_las_audit_reports';

    public static function install() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            engine VARCHAR(20) NOT NULL DEFAULT 'regex',
            url TEXT NULL,
            html_len INT UNSIGNED NULL,
            truncated TINYINT(1) NOT NULL DEFAULT 0,
            timed_out TINYINT(1) NOT NULL DEFAULT 0,
            summary_pass INT UNSIGNED NOT NULL DEFAULT 0,
            summary_fail INT UNSIGNED NOT NULL DEFAULT 0,
            report LONGTEXT NOT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function insert_report( array $row ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->insert( $table, array(
            'engine'       => $row['engine'],
            'url'          => isset($row['url']) ? $row['url'] : '',
            'html_len'     => isset($row['html_len']) ? (int)$row['html_len'] : 0,
            'truncated'    => empty($row['truncated']) ? 0 : 1,
            'timed_out'    => empty($row['timed_out']) ? 0 : 1,
            'summary_pass' => isset($row['summary_pass']) ? (int)$row['summary_pass'] : 0,
            'summary_fail' => isset($row['summary_fail']) ? (int)$row['summary_fail'] : 0,
            'report'       => wp_json_encode( $row['report'] ),
        ), array('%s','%s','%d','%d','%d','%d','%d','%s') );
        return $wpdb->insert_id;
    }

    public static function get_reports( $limit = 20, $offset = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $limit = max(1, (int)$limit);
        $offset = max(0, (int)$offset);
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, created_at, engine, url, html_len, truncated, timed_out, summary_pass, summary_fail
             FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset
        ), ARRAY_A );
    }

    public static function get_report( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $id = (int)$id;
        $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A );
        if ( $row && isset($row['report']) ) { $row['report'] = json_decode($row['report'], true); }
        return $row;
    }
}
