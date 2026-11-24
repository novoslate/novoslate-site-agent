<?php
/**
 * Form lead capture: sends each successful, non-spam form submission to Novoslate Ops, only while "Capture form leads"
 * is on for this client in Ops (read from the response to the daily report, never set in wp-admin).
 *
 * Covers Elementor Pro forms, the Divi contact form, Contact Form 7 and Gravity Forms. Sends only the form name and id,
 * the page URL, the first name, a service or subject field when there is an obvious one, SHA-256 hashes of the email
 * and phone (same normalization as Ops, src/lib/leadRules.ts) and ad attribution. Never message bodies, addresses or
 * other contact details.
 *
 * Sending happens in a scheduled single event (WP-Cron), never during the form request, with retries and backoff, so a
 * failure never breaks or slows the form. Each submission has a submission id so it is sent once.
 *
 * Attribution: a small script stores gclid, gbraid, wbraid, UTMs, landing page and referrer in a first-party cookie
 * (nsa_attr, 90 days): first touch, plus the last Google click. It only loads while capture is on. No other tracking.
 * ?nsa_test=1 on any page sets a session cookie (nsa_test) so that visit's leads are sent marked test; ?nsa_test=0 clears it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NSA_Leads {
	const OPT     = 'novoslate_agent_leads';      // { enabled, endpoint, updated } from the daily report response.
	const SENT    = 'novoslate_agent_leads_sent'; // { submission id: time } for 7 days, so a submission is sent once.
	const LOG     = 'novoslate_agent_leads_log';  // { sent: [times], failed: [times], last_error } for 7 days.
	const CRON    = 'novoslate_agent_send_lead';
	const COOKIE  = 'nsa_attr';
	const BACKOFF = array( 60, 300, 1800, 7200, 21600 );
	const KEYS    = array( 'gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id' );

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'send' ), 10, 3 );
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'wp_head', array( __CLASS__, 'print_script' ), 1 );
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'on_cf7' ), 20 );
		add_action( 'gform_after_submission', array( __CLASS__, 'on_gravity' ), 20, 2 );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'on_elementor' ), 20, 2 );
		add_action( 'et_pb_contact_form_submit', array( __CLASS__, 'on_divi' ), 20, 3 );
	}

	public static function deactivate() {
		wp_unschedule_hook( self::CRON );
	}

	/* ---------- Setting from Ops ---------- */

	public static function settings() {
		$o = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $o ) ? $o : array(), array( 'enabled' => false, 'endpoint' => '', 'updated' => 0 ) );
	}

	public static function enabled() {
		$o = self::settings();
		return ! empty( $o['enabled'] ) && '' !== $o['endpoint'];
	}

	/** Store the lead_capture block from the daily report response. */
	public static function apply_setting( $lc ) {
		if ( ! is_array( $lc ) ) {
			return;
		}
		$endpoint = isset( $lc['endpoint'] ) ? esc_url_raw( (string) $lc['endpoint'] ) : '';
		if ( 0 !== strpos( $endpoint, 'https://' ) && 0 !== strpos( $endpoint, 'http://localhost' ) ) {
			$endpoint = '';
		}
		update_option( self::OPT, array( 'enabled' => ! empty( $lc['enabled'] ), 'endpoint' => $endpoint, 'updated' => time() ), false );
	}

	/* ---------- Attribution ---------- */

	public static function print_script() {
		if ( is_admin() ) {
			return;
		}
		$keys = wp_json_encode( self::KEYS );
		// First touch (f): landing page, outside referrer and any ad params of the first visit. Last Google click (l):
		// updated on every visit that carries gclid, gbraid or wbraid. Path only, never the query string.
		echo "<script id=\"nsa-attr\">(function(){try{var K='nsa_attr',q=new URLSearchParams(location.search),x=q.get('nsa_test'),sc=location.protocol==='https:'?'; Secure':'';if(x==='1'){document.cookie='nsa_test=1; path=/; SameSite=Lax'+sc}else if(x==='0'){document.cookie='nsa_test=; path=/; max-age=0; SameSite=Lax'+sc}var ks=" . $keys . ",c=null,m=document.cookie.match(/(?:^|; )nsa_attr=([^;]*)/);if(m){try{c=JSON.parse(decodeURIComponent(m[1]))}catch(e){c=null}}var t=Math.floor(Date.now()/1000),h={},ad=false,ch=false,lp=(location.origin+location.pathname).slice(0,300),r=document.referrer&&document.referrer.indexOf(location.host)<0?document.referrer.split('?')[0].slice(0,300):'';ks.forEach(function(k){var v=q.get(k);if(v){h[k]=v.slice(0,200);if(k==='gclid'||k==='gbraid'||k==='wbraid')ad=true}});if(!c||!c.f){c={f:{lp:lp,ref:r,t:t}};for(var k in h)c.f[k]=h[k];ch=true}if(ad){c.l={lp:lp,t:t};['gclid','gbraid','wbraid'].forEach(function(k){if(h[k])c.l[k]=h[k]});ch=true}if(ch){document.cookie=K+'='+encodeURIComponent(JSON.stringify(c))+'; path=/; max-age='+(90*86400)+'; SameSite=Lax'+(location.protocol==='https:'?'; Secure':'')}}catch(e){}})();</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Attribution from the cookie, for the payload. Google click ids come from the last Google click when there is one. */
	public static function attribution() {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$c   = json_decode( (string) $raw, true );
		if ( ! is_array( $c ) ) {
			return array();
		}
		$f   = isset( $c['f'] ) && is_array( $c['f'] ) ? $c['f'] : array();
		$l   = isset( $c['l'] ) && is_array( $c['l'] ) ? $c['l'] : array();
		$out = array();
		foreach ( self::KEYS as $k ) {
			if ( ! empty( $f[ $k ] ) && is_scalar( $f[ $k ] ) ) {
				$out[ $k ] = substr( sanitize_text_field( (string) $f[ $k ] ), 0, 200 );
			}
		}
		foreach ( array( 'gclid', 'gbraid', 'wbraid' ) as $k ) {
			if ( ! empty( $l[ $k ] ) && is_scalar( $l[ $k ] ) ) {
				if ( ! empty( $out[ $k ] ) && $out[ $k ] !== $l[ $k ] ) {
					$out[ 'first_' . $k ] = $out[ $k ];
				}
				$out[ $k ] = substr( sanitize_text_field( (string) $l[ $k ] ), 0, 200 );
			}
		}
		if ( ! empty( $f['lp'] ) ) {
			$out['landing_page'] = esc_url_raw( substr( (string) $f['lp'], 0, 300 ) );
		}
		if ( ! empty( $f['ref'] ) ) {
			$out['referrer'] = esc_url_raw( substr( (string) $f['ref'], 0, 300 ) );
		}
		if ( ! empty( $f['t'] ) ) {
			$out['first_seen'] = gmdate( 'c', (int) $f['t'] );
		}
		if ( ! empty( $l['t'] ) ) {
			$out['last_click_at'] = gmdate( 'c', (int) $l['t'] );
			if ( ! empty( $l['lp'] ) ) {
				$out['last_click_page'] = esc_url_raw( substr( (string) $l['lp'], 0, 300 ) );
			}
		}
		return $out;
	}

	/* ---------- Hashing (must match Ops: src/lib/leadRules.ts) ---------- */

	public static function email_hash( $email ) {
		$e = strtolower( trim( (string) $email ) );
		return is_email( $e ) ? hash( 'sha256', $e ) : '';
	}

	public static function phone_hash( $phone ) {
		$raw = trim( (string) $phone );
		$d   = preg_replace( '/\D/', '', $raw );
		if ( '' === $d ) {
			return '';
		}
		if ( 0 !== strpos( $raw, '+' ) && 10 === strlen( $d ) ) {
			$d = '1' . $d;
		}
		return strlen( $d ) >= 8 && strlen( $d ) <= 15 ? hash( 'sha256', '+' . $d ) : '';
	}

	/* ---------- Fields ---------- */

	/**
	 * Pick email, phone, first name and an obvious service or subject field from a list of
	 * array( key, label, type, value ). Everything else (messages, addresses, other fields) is dropped.
	 */
	public static function extract( array $fields ) {
		$email   = '';
		$phone   = '';
		$first   = '';
		$full    = '';
		$service = '';
		foreach ( $fields as $f ) {
			$k = strtolower( ( isset( $f['key'] ) ? $f['key'] : '' ) . ' ' . ( isset( $f['label'] ) ? $f['label'] : '' ) );
			$t = strtolower( isset( $f['type'] ) ? (string) $f['type'] : '' );
			$v = isset( $f['value'] ) ? $f['value'] : '';
			if ( is_array( $v ) ) {
				$v = implode( ', ', array_filter( array_map( 'strval', array_filter( $v, 'is_scalar' ) ) ) );
			}
			$v = trim( (string) $v );
			if ( '' === $v || in_array( $t, array( 'hidden', 'file', 'upload', 'password', 'honeypot', 'recaptcha', 'captcha' ), true ) ) {
				continue;
			}
			if ( '' === $email && ( 'email' === $t || preg_match( '/e-?mail/', $k ) ) && is_email( $v ) ) {
				$email = $v;
				continue;
			}
			if ( '' === $phone && ( in_array( $t, array( 'tel', 'phone' ), true ) || preg_match( '/phone|mobile|cell|\btel\b/', $k ) ) && strlen( preg_replace( '/\D/', '', $v ) ) >= 7 ) {
				$phone = $v;
				continue;
			}
			if ( '' === $first && preg_match( '/first.?name|\bfname\b|given.?name/', $k ) ) {
				$first = $v;
				continue;
			}
			if ( preg_match( '/last.?name|surname|\blname\b|family.?name/', $k ) ) {
				continue;
			}
			if ( '' === $full && preg_match( '/name/', $k ) && ! preg_match( '/company|business|organi[sz]ation|file|user|pet|dog|event/', $k ) && in_array( $t, array( '', 'text', 'name' ), true ) && strlen( $v ) <= 80 ) {
				$full = $v;
				continue;
			}
			if ( '' === $service && ! in_array( $t, array( 'textarea', 'message' ), true ) && preg_match( '/service|subject|project|interest|inquiry|enquiry|reason|topic|help with|looking for|type of/', $k ) && strlen( $v ) <= 100 && false === strpos( $v, "\n" ) ) {
				$service = $v;
			}
		}
		if ( '' === $first && '' !== $full ) {
			$parts = preg_split( '/\s+/', $full );
			$first = $parts[0];
		}
		return array(
			'first_name' => substr( sanitize_text_field( $first ), 0, 60 ),
			'service'    => substr( sanitize_text_field( $service ), 0, 100 ),
			'email'      => $email,
			'phone'      => $phone,
		);
	}

	/**
	 * A test submission: the session cookie set by visiting any page with ?nsa_test=1, nsa_test=1 in the form page or
	 * referrer URL, or the NSA_LEADS_TEST constant. Ops then stores the lead as test and leaves it out of every count.
	 */
	public static function is_test( $page ) {
		if ( defined( 'NSA_LEADS_TEST' ) && NSA_LEADS_TEST ) {
			return true;
		}
		if ( isset( $_COOKIE['nsa_test'] ) && '1' === (string) wp_unslash( $_COOKIE['nsa_test'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return true;
		}
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( array( (string) $page, $ref ) as $u ) {
			$qs = (string) wp_parse_url( $u, PHP_URL_QUERY );
			if ( '' !== $qs ) {
				parse_str( $qs, $p );
				if ( isset( $p['nsa_test'] ) && '1' === (string) $p['nsa_test'] ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function page_url( $url ) {
		$url = (string) $url;
		$q   = strpos( $url, '?' );
		return esc_url_raw( substr( false === $q ? $url : substr( $url, 0, $q ), 0, 300 ) );
	}

	private static function prop( $o, $k ) {
		if ( is_array( $o ) || $o instanceof ArrayAccess ) {
			return isset( $o[ $k ] ) ? $o[ $k ] : null;
		}
		return is_object( $o ) && isset( $o->$k ) ? $o->$k : null;
	}

	/* ---------- Capture ---------- */

	/**
	 * Build the payload and schedule sending. Runs inside the form request, so it never throws and never calls Ops.
	 */
	public static function capture( $plugin, $form_id, $form_name, array $fields, $seed, $page ) {
		try {
			if ( ! self::enabled() ) {
				return;
			}
			$x = self::extract( $fields );
			if ( '' === $x['email'] && '' === $x['phone'] ) {
				return; // Not a lead (search, newsletter without contact, etc.).
			}
			$sid  = substr( preg_replace( '/[^A-Za-z0-9_.-]/', '', $plugin . '-' . $form_id . '-' . $seed ), 0, 120 );
			$sent = get_option( self::SENT, array() );
			if ( isset( $sent[ $sid ] ) || get_transient( 'nsa_lead_' . md5( $sid ) ) ) {
				return;
			}
			set_transient( 'nsa_lead_' . md5( $sid ), 1, 600 );
			$payload = array(
				'source'        => 'form',
				'external_id'   => $sid,
				'created_at'    => gmdate( 'c' ),
				'form_plugin'   => $plugin,
				'form_name'     => substr( sanitize_text_field( (string) $form_name ), 0, 120 ),
				'form_id'       => substr( sanitize_text_field( (string) $form_id ), 0, 60 ),
				'page'          => self::page_url( $page ),
				'name'          => $x['first_name'],
				'service'       => $x['service'],
				'email_hash'    => self::email_hash( $x['email'] ),
				'phone_hash'    => self::phone_hash( $x['phone'] ),
				'attribution'   => self::attribution(),
				'site'          => home_url( '/' ),
				'agent_version' => NSA_VERSION,
				'test'          => self::is_test( $page ),
			);
			wp_schedule_single_event( time(), self::CRON, array( $sid, $payload, 0 ) );
		} catch ( \Throwable $e ) {
			self::log( 'failed', 'capture: ' . $e->getMessage() );
		}
	}

	/** Contact Form 7: wpcf7_mail_sent fires only after a valid, non-spam submission. */
	public static function on_cf7( $form ) {
		try {
			if ( ! class_exists( 'WPCF7_Submission' ) ) {
				return;
			}
			$sub = WPCF7_Submission::get_instance();
			if ( ! $sub ) {
				return;
			}
			$data   = (array) $sub->get_posted_data();
			$fields = array();
			foreach ( $form->scan_form_tags() as $tag ) {
				if ( empty( $tag->name ) ) {
					continue;
				}
				$fields[] = array( 'key' => $tag->name, 'label' => $tag->name, 'type' => $tag->basetype, 'value' => isset( $data[ $tag->name ] ) ? $data[ $tag->name ] : '' );
			}
			$seed = method_exists( $sub, 'get_posted_data_hash' ) ? $sub->get_posted_data_hash() : md5( wp_json_encode( $data ) );
			self::capture( 'cf7', $form->id(), $form->title(), $fields, $seed . '-' . (int) $sub->get_meta( 'timestamp' ), (string) $sub->get_meta( 'url' ) );
		} catch ( \Throwable $e ) {
			self::log( 'failed', 'cf7: ' . $e->getMessage() );
		}
	}

	/** Gravity Forms: skips entries Gravity Forms marked as spam. */
	public static function on_gravity( $entry, $form ) {
		try {
			$status = self::prop( $entry, 'status' );
			if ( $status && 'active' !== $status ) {
				return;
			}
			$fields = array();
			foreach ( (array) self::prop( $form, 'fields' ) as $field ) {
				$id    = (string) self::prop( $field, 'id' );
				$type  = (string) self::prop( $field, 'type' );
				$label = trim( self::prop( $field, 'label' ) . ' ' . self::prop( $field, 'adminLabel' ) );
				if ( 'name' === $type ) {
					$fields[] = array( 'key' => 'first name', 'label' => $label, 'type' => 'text', 'value' => self::prop( $entry, $id . '.3' ) );
					continue;
				}
				if ( in_array( $type, array( 'address', 'textarea', 'fileupload', 'consent', 'html', 'section', 'page', 'captcha' ), true ) ) {
					continue;
				}
				$inputs = self::prop( $field, 'inputs' );
				if ( is_array( $inputs ) && $inputs ) {
					$vals = array();
					foreach ( $inputs as $in ) {
						$vals[] = self::prop( $entry, (string) self::prop( $in, 'id' ) );
					}
					$value = array_filter( $vals );
				} else {
					$value = self::prop( $entry, $id );
				}
				$fields[] = array( 'key' => $type, 'label' => $label, 'type' => $type, 'value' => $value );
			}
			self::capture( 'gf', self::prop( $form, 'id' ), self::prop( $form, 'title' ), $fields, (string) self::prop( $entry, 'id' ), (string) self::prop( $entry, 'source_url' ) );
		} catch ( \Throwable $e ) {
			self::log( 'failed', 'gravity: ' . $e->getMessage() );
		}
	}

	/**
	 * Elementor Pro forms: new_record fires only after validation passed (required fields, honeypot, reCAPTCHA, Akismet
	 * all stop the request before it), and after the form's actions ran. A failed action (the form's email, a webhook)
	 * sets is_success to false but the submission is still a real lead, so only field errors skip it.
	 */
	public static function on_elementor( $record, $handler ) {
		try {
			if ( is_object( $handler ) && ! empty( $handler->errors ) ) {
				return;
			}
			$fields = array();
			foreach ( (array) $record->get( 'fields' ) as $id => $f ) {
				$fields[] = array(
					'key'   => (string) $id,
					'label' => isset( $f['title'] ) ? (string) $f['title'] : '',
					'type'  => isset( $f['type'] ) ? (string) $f['type'] : '',
					'value' => isset( $f['raw_value'] ) ? $f['raw_value'] : ( isset( $f['value'] ) ? $f['value'] : '' ),
				);
			}
			$form_id = (string) $record->get_form_settings( 'id' );
			$page    = isset( $_POST['referrer'] ) ? wp_unslash( $_POST['referrer'] ) : wp_get_referer(); // phpcs:ignore WordPress.Security
			self::capture( 'elementor', $form_id, (string) $record->get_form_settings( 'form_name' ), $fields, self::seed( $fields ), (string) $page );
		} catch ( \Throwable $e ) {
			self::log( 'failed', 'elementor: ' . $e->getMessage() );
		}
	}

	/** Divi contact form: the action passes $et_contact_error, true when the captcha or spam check failed. */
	public static function on_divi( $values, $et_contact_error, $info ) {
		try {
			if ( $et_contact_error ) {
				return;
			}
			$fields = array();
			foreach ( (array) $values as $id => $f ) {
				$fields[] = array( 'key' => (string) $id, 'label' => isset( $f['label'] ) ? (string) $f['label'] : '', 'type' => '', 'value' => isset( $f['value'] ) ? $f['value'] : '' );
			}
			$info    = is_array( $info ) ? $info : array();
			$post_id = isset( $info['post_id'] ) ? (int) $info['post_id'] : get_the_ID();
			$num     = isset( $info['contact_form_unique_id'] ) ? $info['contact_form_unique_id'] : ( isset( $info['contact_form_number'] ) ? $info['contact_form_number'] : '0' );
			$form_id = $post_id . '-' . $num;
			$title   = $post_id ? get_the_title( $post_id ) : '';
			$page    = $post_id ? get_permalink( $post_id ) : home_url( add_query_arg( array() ) );
			self::capture( 'divi', $form_id, $title ? 'Contact form (' . $title . ')' : 'Contact form', $fields, self::seed( $fields ), (string) $page );
		} catch ( \Throwable $e ) {
			self::log( 'failed', 'divi: ' . $e->getMessage() );
		}
	}

	/** Submission id for plugins without one: the values, in 30 second windows, so a double click is sent once. */
	private static function seed( array $fields ) {
		return substr( md5( wp_json_encode( wp_list_pluck( $fields, 'value' ) ) . '|' . floor( time() / 30 ) ), 0, 16 );
	}

	/* ---------- Sending (WP-Cron) ---------- */

	public static function send( $sid, $payload, $tries = 0 ) {
		$o = self::settings();
		if ( ! self::enabled() ) {
			return; // Turned off in Ops: drop it.
		}
		$sent = get_option( self::SENT, array() );
		if ( isset( $sent[ $sid ] ) ) {
			return;
		}
		$res  = wp_remote_post(
			$o['endpoint'],
			array(
				'timeout' => 8,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'X-Novoslate-Token' => Novoslate_Site_Agent::token(),
					'User-Agent'        => 'NovoslateSiteAgent/' . NSA_VERSION,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			$now = time();
			foreach ( $sent as $k => $t ) {
				if ( $t < $now - 7 * DAY_IN_SECONDS ) {
					unset( $sent[ $k ] );
				}
			}
			$sent[ $sid ] = $now;
			if ( count( $sent ) > 1000 ) {
				$sent = array_slice( $sent, -1000, null, true );
			}
			update_option( self::SENT, $sent, false );
			self::log( 'sent' );
			return;
		}
		$err = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code;
		if ( 403 === $code && false !== strpos( (string) wp_remote_retrieve_body( $res ), 'capture_off' ) ) {
			update_option( self::OPT, array_merge( $o, array( 'enabled' => false, 'updated' => time() ) ), false );
			self::log( 'failed', 'Capture form leads is off in Ops' );
			return;
		}
		if ( ! in_array( $code, array( 400, 401, 403, 413 ), true ) && $tries < count( self::BACKOFF ) ) {
			wp_schedule_single_event( time() + self::BACKOFF[ $tries ], self::CRON, array( $sid, $payload, $tries + 1 ) );
			self::log( null, $err . ', will retry' );
			return;
		}
		self::log( 'failed', $err );
	}

	private static function log( $kind, $error = null ) {
		$l   = get_option( self::LOG, array() );
		$l   = wp_parse_args( is_array( $l ) ? $l : array(), array( 'sent' => array(), 'failed' => array(), 'last_error' => '' ) );
		$cut = time() - 7 * DAY_IN_SECONDS;
		foreach ( array( 'sent', 'failed' ) as $k ) {
			$l[ $k ] = array_slice( array_values( array_filter( (array) $l[ $k ], function ( $t ) use ( $cut ) { return $t >= $cut; } ) ), -500 );
		}
		if ( $kind ) {
			$l[ $kind ][] = time();
		}
		if ( null !== $error ) {
			$l['last_error'] = substr( gmdate( 'Y-m-d H:i' ) . ' UTC: ' . $error, 0, 200 );
		}
		update_option( self::LOG, $l, false );
	}

	/** For the daily report and the settings page. */
	public static function stats() {
		$l      = get_option( self::LOG, array() );
		$cut    = time() - 7 * DAY_IN_SECONDS;
		$count  = function ( $k ) use ( $l, $cut ) {
			return isset( $l[ $k ] ) ? count( array_filter( (array) $l[ $k ], function ( $t ) use ( $cut ) { return $t >= $cut; } ) ) : 0;
		};
		$queued = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			if ( isset( $hooks[ self::CRON ] ) ) {
				$queued += count( $hooks[ self::CRON ] );
			}
		}
		return array(
			'on'         => self::enabled(),
			'queued'     => $queued,
			'sent_7d'    => $count( 'sent' ),
			'failed_7d'  => $count( 'failed' ),
			'last_error' => isset( $l['last_error'] ) && $l['last_error'] ? $l['last_error'] : null,
		);
	}

	/** Active form plugins (the covered ones first). */
	public static function form_plugins() {
		$out = array();
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			$out[] = 'Elementor Pro';
		}
		$theme = wp_get_theme();
		if ( defined( 'ET_BUILDER_PLUGIN_VERSION' ) || in_array( $theme->get_template(), array( 'Divi', 'Extra' ), true ) ) {
			$out[] = 'Divi';
		}
		if ( defined( 'WPCF7_VERSION' ) ) {
			$out[] = 'Contact Form 7';
		}
		if ( class_exists( 'GFForms' ) ) {
			$out[] = 'Gravity Forms';
		}
		$other = array(
			'WPForms (not covered)'      => defined( 'WPFORMS_VERSION' ),
			'Fluent Forms (not covered)' => defined( 'FLUENTFORM_VERSION' ),
			'Ninja Forms (not covered)'  => class_exists( 'Ninja_Forms' ),
			'Formidable (not covered)'   => class_exists( 'FrmAppHelper' ),
			'Forminator (not covered)'   => defined( 'FORMINATOR_VERSION' ),
		);
		foreach ( $other as $name => $on ) {
			if ( $on ) {
				$out[] = $name;
			}
		}
		return $out;
	}
}
