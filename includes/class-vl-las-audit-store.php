<?php
/**
 * Storage for audit reports.
 *
 * @package VL_LAS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class VL_LAS_Audit_Store {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'vl_las_audit_reports';
    }

    /**
     * Create table (id, created_at, engine, url, summary, report).
     */
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            engine VARCHAR(32) NOT NULL,
            url TEXT NULL,
            summary TEXT NULL,
            report LONGTEXT NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) $charset;";

        dbDelta( $sql );
    }

    public static function save( array $report ) {
        global $wpdb;
        $table = self::table();

        $created_at = isset( $report['ts'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $report['ts'] ) ) : gmdate( 'Y-m-d H:i:s' );
        $engine     = isset( $report['engine'] ) ? (string) $report['engine'] : 'regex';
        $url        = isset( $report['url'] ) ? (string) $report['url'] : '';
        $summary    = isset( $report['summary'] ) ? wp_json_encode( $report['summary'] ) : '';
        $json       = wp_json_encode( $report );

        $ok = $wpdb->insert(
            $table,
            array(
                'created_at' => $created_at,
                'engine'     => $engine,
                'url'        => $url,
                'summary'    => $summary,
                'report'     => $json,
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function get( $id ) {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
        if ( ! $row ) return null;
        $row['report']  = json_decode( (string) $row['report'], true );
        $row['summary'] = json_decode( (string) $row['summary'], true );
        return $row;
    }

    public static function list( $page = 1, $per_page = 10 ) {
        global $wpdb;
        $table = self::table();
        $page     = max( 1, (int) $page );
        $per_page = max( 1, min( 100, (int) $per_page ) );
        $offset   = ( $page - 1 ) * $per_page;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, created_at, engine, url, summary
                 FROM {$table}
                 ORDER BY id DESC
                 LIMIT %d OFFSET %d",
                $per_page, $offset
            ),
            ARRAY_A
        );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        // Decode summary JSON
        foreach ( (array) $rows as &$r ) {
            $r['summary'] = $r['summary'] ? json_decode( $r['summary'], true ) : null;
        }

        return array(
            'items'     => $rows ?: array(),
            'page'      => $page,
            'per_page'  => $per_page,
            'total'     => $total,
            'pages'     => ( $per_page ? (int) ceil( $total / $per_page ) : 1 ),
        );
    }
}
