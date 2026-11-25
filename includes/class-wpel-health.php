<?php
/**
 * Health reports: an hourly summary of this site's email pipeline, posted to
 * a collector the True Market team runs, so the team hears about problems
 * that never raise an alert on the site itself: webhooks that stopped
 * arriving (emails stuck at Sent, so bounces and unopened alerts go quiet),
 * opens that stopped recording (so every email looks unopened), a site that
 * dropped to the SMTP fallback, or a site that stopped reporting at all.
 *
 * Off until a report URL and secret are set (Alerting & Logging tab, or the
 * WPEL_HEALTH_REPORT_URL / WPEL_HEALTH_REPORT_SECRET constants while those
 * fields are blank, see wpel_shared_credential()). Only counts, settings
 * flags and timestamps are sent: no addresses, subjects or message content.
 *
 * This side only reports. Deciding what counts as a problem is left to the
 * collector, so its rules can change without a plugin release.
 *
 * Each report is a JSON POST, signed with the shared secret:
 *   X-WPEL-Timestamp: <unix time>
 *   X-WPEL-Signature: hex HMAC-SHA256 of "<timestamp>.<raw request body>"
 * The collector should recompute the signature over the raw body, compare
 * with hash_equals(), and reject timestamps more than a few minutes old.
 * Any 2xx response counts as accepted. The payload is described on
 * snapshot() below; the collector (TrueMarket/form-alert-collector) depends
 * on it, so bump SCHEMA if a field changes meaning or is removed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPEL_Health_Report {

	/** @var WPEL_Health_Report */
	private static $instance;

	/** Hourly WP-Cron event that sends the report. */
	const CRON_HOOK = 'wpel_health_report';

	/** Option holding the outcome of the last report sent, shown on the Settings page. */
	const STATUS_OPTION = 'wpel_health_report_status';

	/** Prefix of the options holding when something last happened, see mark(). */
	const MARK_PREFIX = 'wpel_health_mark_';

	/** A busy stream of events rewrites its mark at most this often (seconds). */
	const MARK_RESOLUTION = 300;

	/** Payload format version, bumped when a field changes meaning or goes away. */
	const SCHEMA = 1;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'send' ) );

		// Scheduled here rather than only on activation, so a site starts
		// reporting as soon as it's configured (including a clone that
		// inherited the settings from the boilerplate), and picks reporting
		// back up if the event ever goes missing. The cron array is
		// autoloaded, so this is a cheap check.
		if ( $this->is_configured() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/** The collector's URL; a saved setting wins over the constant. */
	public function url() {
		return wpel_shared_credential( 'WPEL_HEALTH_REPORT_URL', 'health_report_url' );
	}

	private function secret() {
		return wpel_shared_credential( 'WPEL_HEALTH_REPORT_SECRET', 'health_report_secret' );
	}

	/** Whether reports go out: both a URL and a secret are needed. */
	public function is_configured() {
		return '' !== $this->url() && '' !== $this->secret();
	}

	/**
	 * Records that something happened now (a webhook arrived, the unopened
	 * check ran). Recorded whether or not reports are on, so the first report
	 * after they're turned on already has real data. One option per mark, so
	 * two requests recording different marks at once can't undo each other.
	 *
	 * @param string $what       e.g. 'webhook'.
	 * @param int    $resolution Skip the write if the saved mark is newer than this many seconds.
	 */
	public static function mark( $what, $resolution = self::MARK_RESOLUTION ) {
		$key = self::MARK_PREFIX . $what;
		$now = time();
		if ( $now - (int) get_option( $key, 0 ) >= $resolution ) {
			update_option( $key, $now, false );
		}
	}

	/** When a mark was last recorded, as a Unix timestamp (0 = never). */
	private function mark_time( $what ) {
		return (int) get_option( self::MARK_PREFIX . $what, 0 );
	}

	/**
	 * Sends a report now. Runs hourly from WP-Cron, and from "Send report
	 * now" on the Settings page.
	 *
	 * @return bool Whether the collector accepted it (false too when reports aren't set up).
	 */
	public function send() {
		if ( ! $this->is_configured() ) {
			return false;
		}

		$body      = wp_json_encode( $this->snapshot() );
		$timestamp = (string) time();
		$response  = wp_remote_post(
			$this->url(),
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-WPEL-Timestamp' => $timestamp,
					'X-WPEL-Signature' => hash_hmac( 'sha256', $timestamp . '.' . $body, $this->secret() ),
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$status = array(
				'ok'    => false,
				'code'  => 0,
				'error' => $response->get_error_message(),
			);
		} else {
			$code   = (int) wp_remote_retrieve_response_code( $response );
			$ok     = $code >= 200 && $code < 300;
			$status = array(
				'ok'    => $ok,
				'code'  => $code,
				'error' => $ok ? '' : wp_html_excerpt( trim( wp_strip_all_tags( wp_remote_retrieve_body( $response ) ) ), 200, '…' ),
			);
		}
		$status['at'] = time();
		update_option( self::STATUS_OPTION, $status, false );

		return $status['ok'];
	}

	/**
	 * Outcome of the last report: ok, code (HTTP status, 0 when the request
	 * never got a response), error, at (Unix time). Empty when none was sent.
	 *
	 * @return array
	 */
	public function last_status() {
		$status = get_option( self::STATUS_OPTION, array() );
		return is_array( $status ) ? $status : array();
	}

	/**
	 * The report itself. Times are UTC ISO 8601 strings, or null for never.
	 *
	 * - schema: goes up when a field changes meaning or is removed. New
	 *   fields can appear without it changing.
	 * - environment: wp_get_environment_type(). Local and staging copies
	 *   cloned from a reporting site report too, under their own `site`.
	 * - config.transport: 'api', 'smtp' (the SMTP fallback) or 'default'
	 *   (WordPress's own, usually PHP mail()).
	 * - activity.last_webhook_at: last Mailgun webhook that passed signature
	 *   verification, of any kind. last_webhook_rejected_at: last one that
	 *   didn't (a wrong or missing signing key, but also any stray POST to
	 *   the endpoint, so only meaningful alongside emails stuck at Sent).
	 *   Both are updated at most every MARK_RESOLUTION seconds.
	 * - activity.last_success_at: last `delivered` webhook or SMTP fallback
	 *   acceptance (the signal outage detection uses).
	 * - activity.last_unopened_check_at: last run of the hourly unopened
	 *   check, whether or not Track opens is on.
	 * - counts.24h / counts.7d: emails logged in that window, by status.
	 *   `opened` counts emails opened at least once. `via_api` counts emails
	 *   with a Mailgun message ID (sent through the API; SMTP fallback and
	 *   default-transport sends have none), so transport 'api' with via_api
	 *   well below `logged` suggests another plugin is sending the mail.
	 * - counts.stuck_pending: emails from the last 7 days still pending after
	 *   an hour (the send died part-way, or another plugin took over
	 *   wp_mail()). counts.stuck_sent_api: API sends from the last 7 days
	 *   still at Sent after two hours; a pile of them means webhooks aren't
	 *   arriving.
	 *
	 * @return array
	 */
	public function snapshot() {
		$o       = get_option( WPEL_OPTION, array() );
		$o       = is_array( $o ) ? $o : array();
		$monitor = WPEL_Mailgun_Monitor::instance();
		$twilio  = $monitor->get_twilio_credentials();
		$numbers = array_filter( array_map( 'trim', explode( ',', isset( $o['twilio_to_numbers'] ) ? (string) $o['twilio_to_numbers'] : '' ) ) );
		$now     = time();

		return array(
			'schema'         => self::SCHEMA,
			'site'           => wp_parse_url( home_url(), PHP_URL_HOST ),
			'home_url'       => home_url(),
			'log_url'        => admin_url( 'admin.php?page=wpel-log' ),
			'environment'    => wp_get_environment_type(),
			'plugin_version' => WPEL_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'generated_at'   => $this->iso( $now ),
			'config'         => array(
				// 'api', 'smtp' or 'default', see WPEL_Mailer::transport().
				'transport'              => WPEL_Mailer::instance()->transport(),
				// Missing key = on, as in WPEL_Mailer.
				'api_sending_enabled'    => ! isset( $o['sending_enabled'] ) || ! empty( $o['sending_enabled'] ),
				'mailgun_domain'         => isset( $o['domain'] ) ? strtolower( trim( (string) $o['domain'] ) ) : '',
				'signing_key_set'        => '' !== wpel_shared_credential( 'WPEL_MAILGUN_SIGNING_KEY', 'signing_key' ),
				'track_opens'            => ! empty( $o['track_opens'] ),
				'watched_emails'         => count( WPEL_Sources::watched( $o ) ),
				'alert_phone_numbers'    => count( $numbers ),
				'twilio_configured'      => '' !== $twilio['account_sid'] && '' !== $twilio['auth_token'] && '' !== $twilio['from_number'],
				'delivery_failure_texts' => $monitor->sms_enabled( $o ),
				'submission_texts'       => $monitor->submission_texts_enabled( $o ),
			),
			'activity'       => array(
				// Any Mailgun webhook that passed signature verification.
				'last_webhook_at'          => $this->iso( $this->mark_time( 'webhook' ) ),
				// A POST to the webhook endpoint that failed verification: a wrong or
				// missing signing key, or just a stray request.
				'last_webhook_rejected_at' => $this->iso( $this->mark_time( 'webhook_rejected' ) ),
				'last_open_at'             => $this->last_open_at(),
				// Last `delivered` webhook or SMTP fallback acceptance, see record_success().
				'last_success_at'          => $this->iso( (int) get_option( 'wpel_last_success', 0 ) ),
				'last_unopened_check_at'   => $this->iso( $this->mark_time( 'unopened_check' ) ),
				'unopened_check_scheduled' => (bool) wp_next_scheduled( 'wpel_check_unopened' ),
				'wp_cron_disabled'         => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'outage_alarm_active'      => (bool) get_transient( 'wpel_outage_alarm_sent' ),
			),
			'counts'         => $this->counts( $now ),
		);
	}

	/**
	 * Emails logged in the last 24 hours and 7 days, by status, plus the ones
	 * that look stuck. `via_api` counts rows with a Mailgun message id, i.e.
	 * sent through the API (or created from a Mailgun event); SMTP fallback
	 * and default-transport sends have none.
	 *
	 * @param int $now Unix time.
	 * @return array
	 */
	private function counts( $now ) {
		global $wpdb;
		$table = WPEL_Mailgun_Monitor::table();
		$out   = array();

		foreach ( array( '24h' => DAY_IN_SECONDS, '7d' => WEEK_IN_SECONDS ) as $label => $span ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS logged,
					        SUM(status = 'pending') AS pending,
					        SUM(status = 'sent') AS sent,
					        SUM(status = 'delivered') AS delivered,
					        SUM(status = 'failed') AS failed,
					        SUM(status = 'temp-fail') AS temp_fail,
					        SUM(status = 'complained') AS complained,
					        SUM(open_count > 0) AS opened,
					        SUM(mailgun_message_id IS NOT NULL AND mailgun_message_id <> '') AS via_api
					   FROM {$table}
					  WHERE created_at >= %s",
					$this->local_time( $now - $span )
				),
				ARRAY_A
			);
			// SUM() is NULL over no rows.
			$out[ $label ] = array_map( 'intval', is_array( $row ) ? $row : array() );
		}

		$week = $this->local_time( $now - WEEK_IN_SECONDS );

		// Captured but never handed off: the send died part-way, or another
		// plugin took over wp_mail() before Form Alert's transport ran.
		$out['stuck_pending'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND created_at >= %s AND created_at < %s",
				$week,
				$this->local_time( $now - HOUR_IN_SECONDS )
			)
		);

		// Accepted by the Mailgun API but never reconciled by a webhook. A few
		// can be slow deliveries; a pile of them means webhooks aren't reaching
		// the site, so bounces and unopened emails aren't being alerted.
		$out['stuck_sent_api'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'sent' AND mailgun_message_id IS NOT NULL AND mailgun_message_id <> '' AND created_at >= %s AND created_at < %s",
				$week,
				$this->local_time( $now - 2 * HOUR_IN_SECONDS )
			)
		);

		return $out;
	}

	/** When an email in the log was last opened (UTC ISO 8601), or null. */
	private function last_open_at() {
		global $wpdb;
		$local = $wpdb->get_var( 'SELECT MAX(last_opened_at) FROM ' . WPEL_Mailgun_Monitor::table() );
		return $local ? get_gmt_from_date( $local, 'Y-m-d\TH:i:s\Z' ) : null;
	}

	/** A Unix time as UTC ISO 8601, or null for 0 (never). */
	private function iso( $timestamp ) {
		return $timestamp ? gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) : null;
	}

	/** A Unix time in the log's created_at form: site-local time, see WPEL_Mailgun_Monitor::local_mysql_time(). */
	private function local_time( $timestamp ) {
		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}
}
