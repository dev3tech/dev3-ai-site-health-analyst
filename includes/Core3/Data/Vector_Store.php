<?php
/**
 * Contract for vector stores, so an external vector database can replace the table later.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by Embeddings_Store; swap it with the `dev3sh_vector_store` filter.
 */
interface Vector_Store {

	/**
	 * Inserts or replaces one object's vector.
	 *
	 * @param string $type   Object type (e.g. "product").
	 * @param int    $id     Object id.
	 * @param array  $vector Floats.
	 * @param string $hash   Hash of the embedded text (stale detection).
	 * @return bool
	 */
	public function upsert( $type, $id, array $vector, $hash );

	/**
	 * Deletes one object's vector, or every vector of a type when $id is null.
	 *
	 * @param string   $type Object type.
	 * @param int|null $id   Object id.
	 * @return int Rows deleted.
	 */
	public function delete( $type, $id = null );

	/**
	 * Nearest neighbours by cosine similarity.
	 *
	 * @param array  $vector Query vector.
	 * @param string $type   Object type.
	 * @param int    $limit  Max results.
	 * @param array  $opts   { min_score, exclude (ids), ids (restrict to) }.
	 * @return array List of { object_id, score }, best first.
	 */
	public function search( array $vector, $type, $limit = 10, array $opts = array() );

	/**
	 * Ids whose stored hash differs from (or is missing for) the given hashes.
	 *
	 * @param string $type   Object type.
	 * @param array  $hashes Map of object id => current hash.
	 * @return int[]
	 */
	public function stale( $type, array $hashes );
}
