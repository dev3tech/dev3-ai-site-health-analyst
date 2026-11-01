<?php
/**
 * Embeddings table: one packed float32 vector per (object_type, object_id), with PHP cosine search.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Data;

use Dev3\AiSiteHealth\Core3\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Column `embedding` (not `vector`, a reserved word in MariaDB 11.7+). Good for up to about 20k vectors per type (768 dims ≈ 3 KB each). Vectors are L2-normalised on
 * write, so cosine similarity is a dot product. Use Embeddings_Store::instance() to honour the
 * `dev3sh_vector_store` filter (external vector databases).
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
class Embeddings_Store implements Vector_Store {

	/**
	 * Rows read per search query.
	 */
	const PAGE = 500;

	/**
	 * Store instance (filterable).
	 *
	 * @return Vector_Store
	 */
	public static function instance() {
		static $store = null;
		if ( null === $store ) {
			/**
			 * Replaces the vector store (must implement Core3\Data\Vector_Store).
			 *
			 * @param Vector_Store $store Default table-backed store.
			 */
			$custom = apply_filters( 'dev3sh_vector_store', new self() );
			$store  = $custom instanceof Vector_Store ? $custom : new self();
		}
		return $store;
	}

	/**
	 * Hash for stale detection (sha1 of the exact text you embed).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function hash( $text ) {
		return sha1( (string) $text );
	}

	/**
	 * Packs floats as little-endian float32.
	 *
	 * @param array $vector Floats.
	 * @return string
	 */
	public static function pack( array $vector ) {
		return pack( 'g*', ...array_map( 'floatval', array_values( $vector ) ) );
	}

	/**
	 * Unpacks a float32 blob.
	 *
	 * @param string $blob Binary.
	 * @return float[]
	 */
	public static function unpack( $blob ) {
		$blob = (string) $blob;
		if ( '' === $blob ) {
			return array();
		}
		return array_values( unpack( 'g*', $blob ) );
	}

	/**
	 * Cosine similarity of two vectors (any length; the shorter one wins).
	 *
	 * @param array $a Vector.
	 * @param array $b Vector.
	 * @return float -1..1
	 */
	public static function cosine( array $a, array $b ) {
		$n   = min( count( $a ), count( $b ) );
		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
			$na  += $a[ $i ] * $a[ $i ];
			$nb  += $b[ $i ] * $b[ $i ];
		}
		return ( $na > 0 && $nb > 0 ) ? $dot / ( sqrt( $na ) * sqrt( $nb ) ) : 0.0;
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table() {
		return Schema::tables()['embeddings'];
	}

	/**
	 * Inserts or replaces a vector.
	 *
	 * @param string $type   Object type.
	 * @param int    $id     Object id.
	 * @param array  $vector Floats.
	 * @param string $hash   Text hash.
	 * @return bool
	 */
	public function upsert( $type, $id, array $vector, $hash ) {
		global $wpdb;
		$vector   = \Dev3\AiSiteHealth\Core3\Api\Gemini_Client::normalize( $vector );
		$type     = substr( sanitize_key( $type ), 0, 40 );
		$data     = array(
			'object_type' => $type,
			'object_id'   => (int) $id,
			'hash'        => substr( (string) $hash, 0, 40 ),
			'dims'        => count( $vector ),
			'embedding'   => self::pack( $vector ),
			'updated_at'  => current_time( 'mysql', true ),
		);
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE object_type = %s AND object_id = %d", $type, (int) $id ) );
		if ( $existing ) {
			return false !== $wpdb->update( $this->table(), $data, array( 'id' => (int) $existing ), array( '%s', '%d', '%s', '%d', '%s', '%s' ), array( '%d' ) );
		}
		return false !== $wpdb->insert( $this->table(), $data, array( '%s', '%d', '%s', '%d', '%s', '%s' ) );
	}

	/**
	 * Deletes a vector or a whole type.
	 *
	 * @param string   $type Object type.
	 * @param int|null $id   Object id.
	 * @return int
	 */
	public function delete( $type, $id = null ) {
		global $wpdb;
		$where = array( 'object_type' => sanitize_key( $type ) );
		if ( null !== $id ) {
			$where['object_id'] = (int) $id;
		}
		return (int) $wpdb->delete( $this->table(), $where );
	}

	/**
	 * One stored row.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return array|null { object_id, hash, dims, vector, updated_at }
	 */
	public function get( $type, $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT object_id, hash, dims, embedding, updated_at FROM {$this->table()} WHERE object_type = %s AND object_id = %d", sanitize_key( $type ), (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['object_id'] = (int) $row['object_id'];
		$row['dims']      = (int) $row['dims'];
		$row['vector']    = self::unpack( $row['embedding'] );
		unset( $row['embedding'] );
		return $row;
	}

	/**
	 * Whether an object needs (re-)embedding.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @param string $hash Current text hash.
	 * @return bool
	 */
	public function is_stale( $type, $id, $hash ) {
		return array() !== $this->stale( $type, array( (int) $id => $hash ) );
	}

	/**
	 * Stale or missing ids.
	 *
	 * @param string $type   Object type.
	 * @param array  $hashes Map id => current hash.
	 * @return int[]
	 */
	public function stale( $type, array $hashes ) {
		global $wpdb;
		if ( ! $hashes ) {
			return array();
		}
		$stored = array();
		foreach ( array_chunk( array_map( 'intval', array_keys( $hashes ) ), 500 ) as $ids ) {
			$in   = implode( ',', $ids );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, hash FROM {$this->table()} WHERE object_type = %s AND object_id IN ({$in})", sanitize_key( $type ) ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$stored[ (int) $row['object_id'] ] = $row['hash'];
			}
		}
		$out = array();
		foreach ( $hashes as $id => $hash ) {
			if ( ! isset( $stored[ (int) $id ] ) || $stored[ (int) $id ] !== $hash ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Stored ids that are no longer in $live_ids (deleted objects), for cleanup.
	 *
	 * @param string $type     Object type.
	 * @param int[]  $live_ids Ids that still exist.
	 * @return int[]
	 */
	public function orphans( $type, array $live_ids ) {
		global $wpdb;
		$stored = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$this->table()} WHERE object_type = %s", sanitize_key( $type ) ) ) );
		return array_values( array_diff( $stored, array_map( 'intval', $live_ids ) ) );
	}

	/**
	 * Vectors stored for a type.
	 *
	 * @param string $type Object type.
	 * @return int
	 */
	public function count( $type ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE object_type = %s", sanitize_key( $type ) ) );
	}

	/**
	 * Cosine search over every vector of a type, paged so memory stays flat.
	 *
	 * @param array  $vector Query vector.
	 * @param string $type   Object type.
	 * @param int    $limit  Max results.
	 * @param array  $opts   { min_score (default 0), exclude (ids), ids (restrict to) }.
	 * @return array List of { object_id, score }.
	 */
	public function search( array $vector, $type, $limit = 10, array $opts = array() ) {
		global $wpdb;
		$query = \Dev3\AiSiteHealth\Core3\Api\Gemini_Client::normalize( $vector );
		$dims  = count( $query );
		if ( ! $dims ) {
			return array();
		}
		$min     = isset( $opts['min_score'] ) ? (float) $opts['min_score'] : 0.0;
		$exclude = isset( $opts['exclude'] ) ? array_flip( array_map( 'intval', (array) $opts['exclude'] ) ) : array();
		$only    = isset( $opts['ids'] ) ? array_flip( array_map( 'intval', (array) $opts['ids'] ) ) : null;
		$limit   = max( 1, (int) $limit );
		$scores  = array();
		$last    = 0;
		$type    = sanitize_key( $type );
		do {
			$rows    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, object_id, embedding FROM {$this->table()} WHERE object_type = %s AND id > %d ORDER BY id LIMIT %d", $type, $last, self::PAGE ), ARRAY_A );
			$fetched = count( $rows );
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				$oid  = (int) $row['object_id'];
				if ( isset( $exclude[ $oid ] ) || ( null !== $only && ! isset( $only[ $oid ] ) ) ) {
					continue;
				}
				$stored = self::unpack( $row['embedding'] );
				$n      = min( $dims, count( $stored ) );
				$dot    = 0.0;
				for ( $i = 0; $i < $n; $i++ ) {
					$dot += $query[ $i ] * $stored[ $i ];
				}
				if ( $dot >= $min ) {
					$scores[ $oid ] = $dot;
				}
			}
			// Keep memory bounded on big sets: trim to the best few hundred between pages.
			if ( count( $scores ) > $limit * 20 + 1000 ) {
				arsort( $scores );
				$scores = array_slice( $scores, 0, $limit * 20, true );
			}
		} while ( self::PAGE === $fetched );

		arsort( $scores );
		$out = array();
		foreach ( array_slice( $scores, 0, $limit, true ) as $oid => $score ) {
			$out[] = array(
				'object_id' => (int) $oid,
				'score'     => round( $score, 6 ),
			);
		}
		return $out;
	}
}
