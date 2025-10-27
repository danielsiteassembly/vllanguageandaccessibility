<?php
/**
 * Enterprise SOC 2 report builder integrating with the VL Hub.
 *
 * @package VL_LAS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'VL_LAS_SOC2' ) ) {

    class VL_LAS_SOC2 {

        const OPTION_SNAPSHOT = 'vl_las_soc2_snapshot';
        const OPTION_REPORT   = 'vl_las_soc2_report';
        const OPTION_META     = 'vl_las_soc2_meta';
        const DEFAULT_ENDPOINT = 'https://hub.visiblelight.ai/wp-json/vl-hub/v1/profile';

        /**
         * Return the configured Hub endpoint.
         */
        public static function get_endpoint() {
            $endpoint = trim( (string) get_option( 'vl_las_soc2_endpoint', '' ) );
            if ( '' === $endpoint ) {
                $endpoint = self::DEFAULT_ENDPOINT;
            }

            $endpoint = apply_filters( 'vl_las_soc2_endpoint', $endpoint );

            return esc_url_raw( $endpoint );
        }

        /**
         * Fetch the SOC 2 snapshot from the VL Hub.
         *
         * @throws \RuntimeException When the request fails or returns invalid data.
         */
        public static function fetch_snapshot() {
            $license = trim( (string) get_option( 'vl_las_license_code', '' ) );
            if ( '' === $license ) {
                throw new \RuntimeException( __( 'Corporate License Code is required before syncing with the VL Hub.', 'vl-las' ) );
            }

            $endpoint = self::get_endpoint();
            $request_url = self::prepare_request_url( $endpoint, $license );

            $args     = array(
                'headers' => array(
                    'Accept'          => 'application/json',
                    'User-Agent'      => 'VL-LAS/' . ( defined( 'VL_LAS_VERSION' ) ? VL_LAS_VERSION : 'dev' ),
                    'X-Luna-License'  => $license,
                ),
                'timeout' => 30,
            );

            /**
             * Allow integrators to adjust the remote request arguments.
             */
            $args = apply_filters( 'vl_las_soc2_request_args', $args, $request_url );

            $response = wp_remote_get( $request_url, $args );

            if ( is_wp_error( $response ) ) {
                throw new \RuntimeException( sprintf(
                    __( 'Hub sync failed: %s', 'vl-las' ),
                    $response->get_error_message()
                ) );
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code >= 400 ) {
                throw new \RuntimeException( sprintf( __( 'Hub sync failed with HTTP %d.', 'vl-las' ), $code ) );
            }

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );
            if ( ! is_array( $data ) ) {
                throw new \RuntimeException( __( 'Hub sync returned invalid JSON.', 'vl-las' ) );
            }

            /**
             * Filter the raw snapshot payload before report generation.
             */
            $data = apply_filters( 'vl_las_soc2_snapshot', $data, $response );

            return $data;
        }

        /**
         * Prepare the request URL by inserting license and refresh parameters when needed.
         */
        public static function prepare_request_url( $endpoint, $license ) {
            $url = trim( (string) $endpoint );
            if ( '' === $url ) {
                return $url;
            }

            $license = trim( (string) $license );

            if ( $license && false !== strpos( $url, '{license}' ) ) {
                $url = str_replace( '{license}', rawurlencode( $license ), $url );
            }

            if ( $license && false === strpos( $url, 'license=' ) ) {
                $url = add_query_arg( 'license', $license, $url );
            }

            if ( false === strpos( $url, 'refresh=' ) ) {
                $url = add_query_arg( 'refresh', '1', $url );
            }

            return $url;
        }

        /**
         * Redact the license value inside a URL when storing metadata for display.
         */
        protected static function redact_license_in_url( $url, $license ) {
            $license = trim( (string) $license );
            if ( '' === $license ) {
                return $url;
            }

            $mask = strlen( $license ) > 8
                ? substr( $license, 0, 4 ) . '…' . substr( $license, -4 )
                : str_repeat( '•', strlen( $license ) );

            $needles = array_unique( array_filter( array(
                $license,
                rawurlencode( $license ),
                urlencode( $license ),
            ) ) );

            foreach ( $needles as $needle ) {
                $url = str_replace( $needle, $mask, $url );
            }

            return $url;
        }

        /**
         * Run the full report pipeline: fetch → analyse → cache.
         */
        public static function run_full_report() {
            $license  = trim( (string) get_option( 'vl_las_license_code', '' ) );
            $snapshot = self::fetch_snapshot();
            $report   = self::generate_report( $snapshot );
            $report['meta']['source_endpoint'] = self::redact_license_in_url( self::prepare_request_url( self::get_endpoint(), $license ), $license );
            $meta     = array(
                'generated_at'   => current_time( 'mysql' ),
                'observation'    => isset( $report['control_tests']['observation_period'] )
                    ? $report['control_tests']['observation_period']
                    : array(),
                'trust_services' => isset( $report['trust_services']['selected'] )
                    ? $report['trust_services']['selected']
                    : array(),
                'analysis_engine' => $report['meta']['analysis_engine'],
                'source_endpoint' => self::redact_license_in_url( self::prepare_request_url( self::get_endpoint(), $license ), $license ),
            );

            update_option( self::OPTION_SNAPSHOT, $snapshot, false );
            update_option( self::OPTION_REPORT, $report, false );
            update_option( self::OPTION_META, $meta, false );

            return array(
                'snapshot' => $snapshot,
                'report'   => $report,
                'meta'     => $meta,
            );
        }

        /**
         * Return cached report data (if any).
         */
        public static function get_cached_bundle() {
            $snapshot = get_option( self::OPTION_SNAPSHOT, array() );
            $report   = get_option( self::OPTION_REPORT, array() );
            $meta     = get_option( self::OPTION_META, array() );

            return array(
                'snapshot' => is_array( $snapshot ) ? $snapshot : array(),
                'report'   => is_array( $report ) ? $report : array(),
                'meta'     => is_array( $meta ) ? $meta : array(),
            );
        }

        /**
         * Build the SOC 2 report using the snapshot payload.
         */
        public static function generate_report( array $snapshot ) {
            $now      = current_time( 'mysql' );
            $trust    = self::normalize_trust_services( $snapshot );
            $controls = self::normalize_control_domains( $snapshot );
            $tests    = self::normalize_tests( $snapshot );
            $risks    = self::normalize_risks( $snapshot );
            $artifacts = self::normalize_artifacts( $snapshot );

            $report = array(
                'meta' => array(
                    'generated_at'    => $now,
                    'type'            => 'SOC 2 Type II',
                    'analysis_engine' => 'Luna AI SOC 2 Copilot',
                    'source_endpoint' => self::redact_license_in_url( self::prepare_request_url( self::get_endpoint(), '' ), '' ),
                    'snapshot_hash'   => md5( wp_json_encode( $snapshot ) ),
                ),
                'trust_services' => array(
                    'selected'          => $trust['selected'],
                    'coverage'          => $trust['coverage'],
                    'obligations'       => $trust['obligations'],
                ),
                'system_description' => self::normalize_system_description( $snapshot ),
                'control_environment' => array(
                    'domains'        => $controls,
                    'control_matrix' => self::build_control_matrix( $controls, $trust['selected'] ),
                ),
                'control_tests' => $tests,
                'risk_assessment' => $risks,
                'auditors'        => self::build_auditor_section( $snapshot, $tests, $risks ),
                'supporting_artifacts' => $artifacts,
            );

            $report['documents'] = array(
                'executive_summary' => self::build_executive_summary( $report ),
                'markdown'          => self::render_markdown( $report ),
            );

            return $report;
        }

        protected static function normalize_trust_services( array $snapshot ) {
            $all = array(
                'Security'             => __( 'Protection against unauthorized access and breaches.', 'vl-las' ),
                'Availability'         => __( 'Systems remain operational and resilient.', 'vl-las' ),
                'Processing Integrity'  => __( 'Data processing is complete, valid, accurate, timely, and authorized.', 'vl-las' ),
                'Confidentiality'      => __( 'Sensitive data is protected throughout its lifecycle.', 'vl-las' ),
                'Privacy'              => __( 'Personal information is collected, used, and retained appropriately.', 'vl-las' ),
            );

            $selected = array();
            if ( ! empty( $snapshot['trust_services'] ) ) {
                $raw = $snapshot['trust_services'];
                if ( isset( $raw['selected'] ) ) {
                    $raw = $raw['selected'];
                }
                if ( is_string( $raw ) ) {
                    $raw = array_map( 'trim', explode( ',', $raw ) );
                }
                if ( is_array( $raw ) ) {
                    foreach ( $raw as $item ) {
                        $label = ucwords( trim( (string) $item ) );
                        if ( isset( $all[ $label ] ) && ! in_array( $label, $selected, true ) ) {
                            $selected[] = $label;
                        }
                    }
                }
            }

            if ( empty( $selected ) ) {
                $selected = array( 'Security', 'Availability', 'Confidentiality' );
            }

            $coverage = array();
            foreach ( $selected as $criterion ) {
                $coverage[] = array(
                    'criterion' => $criterion,
                    'objective' => $all[ $criterion ],
                    'controls'  => self::extract_controls_for_criterion( $snapshot, $criterion ),
                );
            }

            $obligations = array();
            if ( isset( $snapshot['trust_services']['obligations'] ) && is_array( $snapshot['trust_services']['obligations'] ) ) {
                $obligations = $snapshot['trust_services']['obligations'];
            } elseif ( ! empty( $snapshot['trust_services']['notes'] ) ) {
                $obligations[] = (string) $snapshot['trust_services']['notes'];
            }

            return array(
                'selected'    => $selected,
                'coverage'    => $coverage,
                'obligations' => $obligations,
            );
        }

        protected static function normalize_system_description( array $snapshot ) {
            $company = isset( $snapshot['company'] ) && is_array( $snapshot['company'] )
                ? $snapshot['company']
                : array();

            $description = array(
                'company_overview' => array(
                    'name'        => $company['name'] ?? ( $snapshot['company_name'] ?? '' ),
                    'mission'     => $company['mission'] ?? '',
                    'ownership'   => $company['ownership'] ?? '',
                    'structure'   => $company['structure'] ?? '',
                    'headquarters'=> $company['headquarters'] ?? ( $snapshot['headquarters'] ?? '' ),
                ),
                'services_in_scope' => self::ensure_array( $snapshot['services'] ?? $snapshot['services_in_scope'] ?? array() ),
                'infrastructure'    => self::ensure_array( $snapshot['infrastructure'] ?? array() ),
                'software_components' => self::ensure_array( $snapshot['software'] ?? $snapshot['software_components'] ?? array() ),
                'data_flows'        => self::ensure_array( $snapshot['data_flows'] ?? array() ),
                'personnel'         => self::ensure_array( $snapshot['personnel'] ?? array() ),
                'subservice_organizations' => self::ensure_array( $snapshot['subservice_organizations'] ?? $snapshot['vendors'] ?? array() ),
                'control_boundaries'       => self::ensure_array( $snapshot['control_boundaries'] ?? array() ),
                'incident_response'        => self::ensure_array( $snapshot['incident_response'] ?? array() ),
                'business_continuity'      => self::ensure_array( $snapshot['business_continuity'] ?? array() ),
            );

            return $description;
        }

        protected static function normalize_control_domains( array $snapshot ) {
            $domains = array(
                'governance'          => 'Governance & Risk Management',
                'access_control'      => 'Access Control',
                'change_management'   => 'Change Management',
                'system_monitoring'   => 'System Monitoring',
                'incident_response'   => 'Incident Response',
                'vendor_management'   => 'Vendor Management',
                'data_encryption'     => 'Data Encryption',
                'backup_recovery'     => 'Backup & Recovery',
                'onboarding'          => 'Employee Onboarding/Offboarding',
                'privacy'             => 'Privacy & GDPR Alignment',
            );

            $controls = array();
            $source   = isset( $snapshot['controls'] ) && is_array( $snapshot['controls'] ) ? $snapshot['controls'] : array();

            foreach ( $domains as $key => $label ) {
                $row = isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : array();
                $controls[ $key ] = array(
                    'label'    => $label,
                    'status'   => $row['status'] ?? 'operating',
                    'controls' => self::ensure_array( $row['controls'] ?? array() ),
                    'evidence' => self::ensure_array( $row['evidence'] ?? array() ),
                    'owner'    => $row['owner'] ?? '',
                );
            }

            return $controls;
        }

        protected static function normalize_tests( array $snapshot ) {
            $tests = isset( $snapshot['tests'] ) && is_array( $snapshot['tests'] ) ? $snapshot['tests'] : array();

            $period = array(
                'start' => $tests['period']['start'] ?? ( $snapshot['observation_period']['start'] ?? date_i18n( 'Y-m-d', strtotime( '-9 months' ) ) ),
                'end'   => $tests['period']['end'] ?? ( $snapshot['observation_period']['end'] ?? date_i18n( 'Y-m-d' ) ),
            );

            $procedures = self::ensure_array( $tests['procedures'] ?? $snapshot['evidence'] ?? array() );

            return array(
                'type'               => $tests['type'] ?? 'Type II',
                'observation_period' => $period,
                'procedures'         => $procedures,
                'evidence_summary'   => self::ensure_array( $tests['evidence_summary'] ?? $snapshot['evidence_summary'] ?? array() ),
            );
        }

        protected static function normalize_risks( array $snapshot ) {
            $risks = isset( $snapshot['risks'] ) && is_array( $snapshot['risks'] ) ? $snapshot['risks'] : array();

            return array(
                'gaps'        => self::ensure_array( $risks['gaps'] ?? array() ),
                'remediation' => self::ensure_array( $risks['remediation'] ?? array() ),
                'matrix'      => self::ensure_array( $risks['matrix'] ?? array() ),
                'readiness_report' => $risks['readiness_report'] ?? '',
            );
        }

        protected static function normalize_artifacts( array $snapshot ) {
            $artifacts = isset( $snapshot['artifacts'] ) && is_array( $snapshot['artifacts'] ) ? $snapshot['artifacts'] : array();

            $defaults = array(
                'penetration_test'        => '',
                'vulnerability_summary'   => '',
                'business_continuity_plan'=> '',
                'data_flow_diagrams'      => array(),
                'asset_inventory'         => array(),
                'training_evidence'       => array(),
                'vendor_attestations'     => array(),
                'audit_logs'              => array(),
            );

            foreach ( $defaults as $key => $value ) {
                if ( ! isset( $artifacts[ $key ] ) ) {
                    $artifacts[ $key ] = $value;
                }
            }

            foreach ( $artifacts as $key => $value ) {
                if ( is_array( $value ) ) {
                    $artifacts[ $key ] = self::ensure_array( $value );
                }
            }

            return $artifacts;
        }

        protected static function ensure_array( $value ) {
            if ( empty( $value ) ) {
                return array();
            }

            if ( is_array( $value ) ) {
                return array_values( array_filter( $value, static function( $item ) {
                    return ( '' !== $item && null !== $item );
                } ) );
            }

            if ( is_string( $value ) ) {
                $parts = preg_split( '/\r?\n|,/', $value );
                return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
            }

            return array();
        }

        protected static function sanitize_list_values( array $items ) {
            return array_values( array_filter( array_map( 'sanitize_text_field', $items ), 'strlen' ) );
        }

        protected static function sanitize_sentence( $value ) {
            return sanitize_textarea_field( (string) $value );
        }

        protected static function sanitize_markdown_block( $value ) {
            if ( is_array( $value ) || is_object( $value ) ) {
                $value = wp_json_encode( $value );
            }

            $value = wp_strip_all_tags( (string) $value );
            $value = preg_replace( "/[\r\n]+/", "\n", $value );

            return sanitize_textarea_field( $value );
        }

        protected static function format_artifact_label( $key ) {
            $label = sanitize_text_field( (string) $key );
            $label = str_replace( array( '-', '_' ), ' ', $label );
            $label = preg_replace( '/\s+/', ' ', $label );
            $label = trim( $label );

            if ( '' === $label ) {
                return '';
            }

            return ucwords( $label );
        }

        protected static function extract_controls_for_criterion( array $snapshot, $criterion ) {
            $map = array(
                'Security'            => array( 'governance', 'access_control', 'system_monitoring', 'incident_response' ),
                'Availability'        => array( 'system_monitoring', 'backup_recovery', 'vendor_management' ),
                'Processing Integrity' => array( 'change_management', 'system_monitoring' ),
                'Confidentiality'     => array( 'access_control', 'data_encryption', 'vendor_management' ),
                'Privacy'             => array( 'privacy', 'data_encryption', 'onboarding' ),
            );

            $selected = $map[ $criterion ] ?? array();
            $controls = array();

            $source = isset( $snapshot['controls'] ) && is_array( $snapshot['controls'] ) ? $snapshot['controls'] : array();

            foreach ( $selected as $key ) {
                if ( isset( $source[ $key ] ) ) {
                    $row = $source[ $key ];
                    $controls[] = array(
                        'domain'   => $key,
                        'summary'  => isset( $row['summary'] ) ? $row['summary'] : ( isset( $row['controls'] ) ? implode( '; ', (array) $row['controls'] ) : '' ),
                        'status'   => $row['status'] ?? 'operating',
                    );
                }
            }

            return $controls;
        }

        protected static function build_control_matrix( array $controls, array $trust_services ) {
            $matrix = array();
            foreach ( $controls as $key => $row ) {
                $matrix[] = array(
                    'domain'      => $row['label'],
                    'owner'       => $row['owner'],
                    'status'      => $row['status'],
                    'controls'    => $row['controls'],
                    'evidence'    => $row['evidence'],
                    'aligned_tsc' => self::map_domain_to_tsc( $key, $trust_services ),
                );
            }
            return $matrix;
        }

        protected static function map_domain_to_tsc( $domain, array $trust_services ) {
            $domain_map = array(
                'governance'        => array( 'Security' ),
                'access_control'    => array( 'Security', 'Confidentiality' ),
                'change_management' => array( 'Processing Integrity', 'Security' ),
                'system_monitoring' => array( 'Security', 'Availability' ),
                'incident_response' => array( 'Security', 'Availability' ),
                'vendor_management' => array( 'Security', 'Confidentiality', 'Privacy' ),
                'data_encryption'   => array( 'Security', 'Confidentiality', 'Privacy' ),
                'backup_recovery'   => array( 'Availability', 'Security' ),
                'onboarding'        => array( 'Security', 'Privacy' ),
                'privacy'           => array( 'Privacy', 'Confidentiality' ),
            );

            $mapped = $domain_map[ $domain ] ?? array();
            if ( empty( $mapped ) ) {
                return $trust_services;
            }

            return array_values( array_intersect( $mapped, $trust_services ) );
        }

        protected static function build_auditor_section( array $snapshot, array $tests, array $risks ) {
            $default_name     = __( 'Luna AI Independent Service Auditor', 'vl-las' );
            $default_opinion  = __( 'Controls were suitably designed and operated effectively throughout the observation period.', 'vl-las' );
            $default_status   = __( 'Unqualified', 'vl-las' );
            $default_assertion = __( 'Management asserts that the accompanying description fairly presents the system and that the controls were suitably designed and operated effectively.', 'vl-las' );

            $auditor_name = isset( $snapshot['auditor']['name'] )
                ? sanitize_text_field( $snapshot['auditor']['name'] )
                : $default_name;
            $opinion = isset( $snapshot['auditor']['opinion'] )
                ? self::sanitize_sentence( $snapshot['auditor']['opinion'] )
                : $default_opinion;
            $status = isset( $snapshot['auditor']['status'] )
                ? sanitize_text_field( $snapshot['auditor']['status'] )
                : $default_status;
            $assertion = isset( $snapshot['management_assertion'] )
                ? self::sanitize_sentence( $snapshot['management_assertion'] )
                : $default_assertion;

            return array(
                'independent_auditor' => $auditor_name,
                'opinion'             => $opinion,
                'status'              => $status,
                'support'             => array(
                    'testing_highlights' => self::sanitize_list_values( isset( $tests['procedures'] ) ? (array) $tests['procedures'] : array() ),
                    'risk_considerations'=> self::sanitize_list_values( isset( $risks['gaps'] ) ? (array) $risks['gaps'] : array() ),
                ),
                'management_assertion' => $assertion,
            );
        }

        protected static function build_executive_summary( array $report ) {
            $company = $report['system_description']['company_overview']['name'] ?? __( 'Client', 'vl-las' );
            $company = sanitize_text_field( $company ?: __( 'Client', 'vl-las' ) );

            $tsc_list = ! empty( $report['trust_services']['selected'] )
                ? self::sanitize_list_values( (array) $report['trust_services']['selected'] )
                : array( __( 'baseline criteria', 'vl-las' ) );
            $tsc     = implode( ', ', $tsc_list );

            $period  = $report['control_tests']['observation_period'];
            $period_text = trim( sprintf( '%s – %s', $period['start'] ?? '', $period['end'] ?? '' ), ' -' );
            $period_text = $period_text ? sanitize_text_field( $period_text ) : __( 'the observation period', 'vl-las' );

            $summary = sprintf(
                /* translators: 1: company name, 2: trust services criteria, 3: observation period */
                __( '%1$s completed a SOC 2 Type II engagement covering the %2$s trust services criteria for %3$s. Luna AI verified that key governance, security, availability, and privacy controls are operating effectively with evidence centrally managed in the VL Hub.', 'vl-las' ),
                $company,
                $tsc,
                $period_text
            );

            return $summary;
        }

        protected static function render_markdown( array $report ) {
            $lines  = array();
            $system = isset( $report['system_description'] ) && is_array( $report['system_description'] ) ? $report['system_description'] : array();

            $company = isset( $system['company_overview']['name'] ) ? sanitize_text_field( $system['company_overview']['name'] ) : '';
            $company = $company ?: __( 'Client', 'vl-las' );

            $period = isset( $report['control_tests']['observation_period'] ) && is_array( $report['control_tests']['observation_period'] )
                ? $report['control_tests']['observation_period']
                : array();
            $period_start = isset( $period['start'] ) ? sanitize_text_field( $period['start'] ) : '';
            $period_end   = isset( $period['end'] ) ? sanitize_text_field( $period['end'] ) : '';
            $period_parts = array_filter( array( $period_start, $period_end ), 'strlen' );
            $period_text  = $period_parts ? implode( ' – ', $period_parts ) : '';

            $generated       = isset( $report['meta']['generated_at'] ) ? sanitize_text_field( $report['meta']['generated_at'] ) : '';
            $analysis_engine = isset( $report['meta']['analysis_engine'] ) ? sanitize_text_field( $report['meta']['analysis_engine'] ) : 'Luna AI SOC 2 Copilot';
            $trust_selected  = ! empty( $report['trust_services']['selected'] )
                ? self::sanitize_list_values( (array) $report['trust_services']['selected'] )
                : array();

            $lines[] = '# SOC 2 Type II Report';
            $lines[] = '';
            $lines[] = '**Organization:** ' . $company;
            $lines[] = '**Generated:** ' . ( $generated ? $generated : __( 'Not specified', 'vl-las' ) );
            $lines[] = '**Observation Period:** ' . ( $period_text ? $period_text : __( 'Not specified', 'vl-las' ) );
            $lines[] = '**Trust Services Criteria:** ' . ( $trust_selected ? implode( ', ', $trust_selected ) : __( 'Not specified', 'vl-las' ) );
            $lines[] = '**Analysis Engine:** ' . $analysis_engine;
            $lines[] = '';

            $lines[] = '## Executive Summary';
            $summary_block = isset( $report['documents']['executive_summary'] )
                ? self::sanitize_markdown_block( $report['documents']['executive_summary'] )
                : '';
            $lines[] = $summary_block ? $summary_block : __( 'Summary not provided.', 'vl-las' );
            $lines[] = '';

            $lines[] = '## System Description';
            $company_overview = isset( $system['company_overview'] ) && is_array( $system['company_overview'] ) ? $system['company_overview'] : array();
            $mission   = isset( $company_overview['mission'] ) ? sanitize_text_field( $company_overview['mission'] ) : '';
            $ownership = isset( $company_overview['ownership'] ) ? sanitize_text_field( $company_overview['ownership'] ) : '';
            $mission_line = $mission ? $mission : __( 'Not provided', 'vl-las' );
            if ( $ownership ) {
                $mission_line .= ' — ' . $ownership;
            }
            $lines[] = '- **Mission & Ownership:** ' . $mission_line;

            if ( ! empty( $system['services_in_scope'] ) ) {
                $lines[] = '- **Services in Scope:** ' . implode( ', ', self::sanitize_list_values( (array) $system['services_in_scope'] ) );
            }
            if ( ! empty( $system['infrastructure'] ) ) {
                $lines[] = '- **Infrastructure Footprint:** ' . implode( '; ', self::sanitize_list_values( (array) $system['infrastructure'] ) );
            }
            if ( ! empty( $system['software_components'] ) ) {
                $lines[] = '- **Software Components:** ' . implode( '; ', self::sanitize_list_values( (array) $system['software_components'] ) );
            }
            if ( ! empty( $system['data_flows'] ) ) {
                $lines[] = '- **Data Flows:** ' . implode( '; ', self::sanitize_list_values( (array) $system['data_flows'] ) );
            }
            if ( ! empty( $system['personnel'] ) ) {
                $lines[] = '- **Personnel & Responsibilities:** ' . implode( '; ', self::sanitize_list_values( (array) $system['personnel'] ) );
            }
            if ( ! empty( $system['subservice_organizations'] ) ) {
                $lines[] = '- **Subservice Organizations:** ' . implode( '; ', self::sanitize_list_values( (array) $system['subservice_organizations'] ) );
            }
            if ( ! empty( $system['control_boundaries'] ) ) {
                $lines[] = '- **Control Boundaries:** ' . implode( '; ', self::sanitize_list_values( (array) $system['control_boundaries'] ) );
            }
            if ( ! empty( $system['incident_response'] ) ) {
                $lines[] = '- **Incident Response & Continuity:** ' . implode( '; ', self::sanitize_list_values( (array) $system['incident_response'] ) );
            }
            if ( ! empty( $system['business_continuity'] ) ) {
                $lines[] = '- **Business Continuity & DR:** ' . implode( '; ', self::sanitize_list_values( (array) $system['business_continuity'] ) );
            }
            $lines[] = '';

            $lines[] = '## Control Environment';
            $domains = isset( $report['control_environment']['domains'] ) && is_array( $report['control_environment']['domains'] )
                ? $report['control_environment']['domains']
                : array();
            foreach ( $domains as $domain ) {
                $label  = isset( $domain['label'] ) ? sanitize_text_field( $domain['label'] ) : '';
                $status = isset( $domain['status'] ) ? sanitize_text_field( $domain['status'] ) : '';
                if ( $label || $status ) {
                    $entry = '- **' . ( $label ? $label : __( 'Domain', 'vl-las' ) ) . '**';
                    if ( $status ) {
                        $entry .= ' (' . $status . ')';
                    }
                    $lines[] = $entry;
                }
                if ( ! empty( $domain['controls'] ) ) {
                    foreach ( self::sanitize_list_values( (array) $domain['controls'] ) as $control ) {
                        $lines[] = '  - ' . $control;
                    }
                }
                if ( ! empty( $domain['evidence'] ) ) {
                    $lines[] = '  - Evidence: ' . implode( ', ', self::sanitize_list_values( (array) $domain['evidence'] ) );
                }
            }
            $lines[] = '';

            $lines[] = '## Tests of Operating Effectiveness';
            $type = isset( $report['control_tests']['type'] ) ? sanitize_text_field( $report['control_tests']['type'] ) : '';
            $lines[] = '- **Type:** ' . ( $type ? $type : __( 'Not specified', 'vl-las' ) );
            $procedures = self::sanitize_list_values( isset( $report['control_tests']['procedures'] ) ? (array) $report['control_tests']['procedures'] : array() );
            foreach ( $procedures as $proc ) {
                $lines[] = '  - ' . $proc;
            }
            $evidence_summary = self::sanitize_list_values( isset( $report['control_tests']['evidence_summary'] ) ? (array) $report['control_tests']['evidence_summary'] : array() );
            if ( $evidence_summary ) {
                $lines[] = '- **Evidence Summary:** ' . implode( '; ', $evidence_summary );
            }
            $lines[] = '';

            $lines[] = '## Risk Assessment & Remediation';
            $gaps        = self::sanitize_list_values( isset( $report['risk_assessment']['gaps'] ) ? (array) $report['risk_assessment']['gaps'] : array() );
            $remediation = self::sanitize_list_values( isset( $report['risk_assessment']['remediation'] ) ? (array) $report['risk_assessment']['remediation'] : array() );
            $matrix      = self::sanitize_list_values( isset( $report['risk_assessment']['matrix'] ) ? (array) $report['risk_assessment']['matrix'] : array() );
            if ( $gaps ) {
                $lines[] = '- **Control Gaps:** ' . implode( '; ', $gaps );
            }
            if ( $remediation ) {
                $lines[] = '- **Remediation Plans:** ' . implode( '; ', $remediation );
            }
            if ( $matrix ) {
                $lines[] = '- **Risk Matrix Highlights:** ' . implode( '; ', $matrix );
            }
            $lines[] = '';

            $lines[] = '## Auditor\'s Report';
            $auditors = isset( $report['auditors'] ) && is_array( $report['auditors'] ) ? $report['auditors'] : array();
            $lines[] = '- **Independent Service Auditor:** ' . sanitize_text_field( $auditors['independent_auditor'] ?? '' );
            $lines[] = '- **Opinion:** ' . sanitize_text_field( $auditors['opinion'] ?? '' );
            $lines[] = '- **Status:** ' . sanitize_text_field( $auditors['status'] ?? '' );
            $lines[] = '- **Management Assertion:** ' . sanitize_text_field( $auditors['management_assertion'] ?? '' );
            $lines[] = '';

            $lines[] = '## Supporting Artifacts';
            if ( isset( $report['supporting_artifacts'] ) && is_array( $report['supporting_artifacts'] ) ) {
                foreach ( $report['supporting_artifacts'] as $key => $value ) {
                    $label = self::format_artifact_label( $key );
                    if ( '' === $label ) {
                        continue;
                    }
                    if ( is_array( $value ) ) {
                        $list = self::sanitize_list_values( (array) $value );
                        if ( $list ) {
                            $lines[] = '- **' . $label . ':** ' . implode( '; ', $list );
                        }
                    } elseif ( $value ) {
                        $lines[] = '- **' . $label . ':** ' . sanitize_text_field( $value );
                    }
                }
            }

            $lines[] = '';
            $lines[] = '> Generated via VL Language & Accessibility Standards plugin using Luna AI SOC 2 Copilot.';

            return implode( "\n", $lines );
        }
    }
}
