<?php
/**
 * State of a long running export or import.
 *
 * @package PuppyPressMigrator
 */

namespace PuppyPressMigrator;

defined( 'ABSPATH' ) || exit;

/**
 * A job runs in many short steps (AJAX requests or a WP-CLI loop). Its state
 * is stored as JSON in the job folder, so no database tables are needed.
 */
final class Job {

	/**
	 * Job ID.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * State.
	 *
	 * @var array
	 */
	public $state;

	/**
	 * Timestamp (microtime) at which the current request should stop working.
	 * Set by the runner, not persisted.
	 *
	 * @var float
	 */
	public $deadline = INF;

	/**
	 * Set by the runner when another request currently works on this job.
	 *
	 * @var bool
	 */
	public $busy = false;

	/**
	 * Constructor.
	 *
	 * @param string $id    Job ID.
	 * @param array  $state State.
	 */
	private function __construct( $id, array $state ) {
		$this->id    = $id;
		$this->state = $state;
	}

	/**
	 * Starts a new job.
	 *
	 * @param string $type    "export" or "import".
	 * @param array  $options Options.
	 * @return self
	 */
	public static function create( $type, array $options ) {
		$job = new self(
			Storage::create_job_dir(),
			array(
				'type'    => $type,
				'created' => time(),
				'user'    => get_current_user_id(),
				'options' => $options,
				'stage'   => 0,
				'cursor'  => 0,
				'done'    => false,
				'log'     => array(),
				'stats'   => array(),
			)
		);

		$job->save();

		return $job;
	}

	/**
	 * Loads a job.
	 *
	 * @param string $id Job ID.
	 * @return self|null
	 */
	public static function load( $id ) {
		$id   = Storage::sanitize_id( $id );
		$file = Storage::job_dir( $id ) . '/state.json';

		if ( '' === $id || ! is_readable( $file ) ) {
			return null;
		}

		$state = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return is_array( $state ) ? new self( $id, $state ) : null;
	}

	/**
	 * All jobs, newest first.
	 *
	 * @return self[]
	 */
	public static function all() {
		$jobs = array();

		foreach ( (array) glob( Storage::base_dir() . '/jobs/*/state.json' ) as $file ) {
			$job = $file ? self::load( basename( dirname( $file ) ) ) : null;

			if ( $job ) {
				$jobs[] = $job;
			}
		}

		usort(
			$jobs,
			static function ( $a, $b ) {
				return $b->state['created'] - $a->state['created'];
			}
		);

		return $jobs;
	}

	/**
	 * Status for display: running, paused, done or error.
	 *
	 * A job is "running" while some request worked on it within the last minute.
	 *
	 * @return string
	 */
	public function status() {
		if ( ! empty( $this->state['error'] ) ) {
			return 'error';
		}

		if ( $this->state['done'] ) {
			return 'done';
		}

		return ( isset( $this->state['updated'] ) && $this->state['updated'] > time() - MINUTE_IN_SECONDS ) ? 'running' : 'paused';
	}

	/**
	 * Persists the state.
	 */
	public function save() {
		$this->state['updated'] = time();
		file_put_contents( $this->path( 'state.json' ), wp_json_encode( $this->state ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Path of a file inside the job folder.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public function path( $name ) {
		return Storage::job_dir( $this->id ) . '/' . $name;
	}

	/**
	 * Adds a log line.
	 *
	 * @param string $message Message.
	 * @param string $level   info|warning|error.
	 */
	public function log( $message, $level = 'info' ) {
		$this->state['log'][] = array(
			'time'    => time(),
			'level'   => $level,
			'message' => $message,
		);
	}

	/**
	 * Increments a counter.
	 *
	 * @param string $key Counter.
	 * @param int    $by  Amount.
	 */
	public function count( $key, $by = 1 ) {
		$this->state['stats'][ $key ] = ( isset( $this->state['stats'][ $key ] ) ? $this->state['stats'][ $key ] : 0 ) + $by;
	}

	/**
	 * Whether the current request still has time for more work.
	 *
	 * @param float $reserve Seconds to keep in reserve.
	 * @return bool
	 */
	public function has_time( $reserve = 0.0 ) {
		return microtime( true ) + $reserve < $this->deadline;
	}

	/**
	 * Log lines added since a given index.
	 *
	 * @param int $since Index.
	 * @return array
	 */
	public function log_since( $since ) {
		return array_slice( $this->state['log'], max( 0, (int) $since ) );
	}
}
