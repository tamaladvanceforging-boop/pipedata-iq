<?php
/**
 * Generic, registry-driven data access layer (prepared statements only).
 * @package PipeDocIQ
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class PDQ_DB {

	/** Current workspace (multi-tenant extension point). */
	public static function workspace_id() {
		return (int) apply_filters( 'pdq_current_workspace_id', 1 );
	}

	/** Current owner (future RBAC extension point). */
	public static function owner_id() {
		return (int) apply_filters( 'pdq_current_owner_id', get_current_user_id() );
	}

	private static function def( $entity ) {
		$d = PDQ_Registry::get( $entity );
		if ( ! $d ) { return new WP_Error( 'pdq_unknown_entity', 'Unknown entity.', array( 'status' => 404 ) ); }
		return $d;
	}

	private static function all_cols( $d ) {
		$c = array_keys( $d['columns'] );
		return array_merge( array( 'id', 'created_at', 'updated_at' ), $c );
	}

	/** Cast a value to a column's SQL type family; returns [value, format]. */
	private static function cast( $sql, $v ) {
		if ( null === $v || '' === $v ) { return array( null, null ); }
		$s = strtolower( $sql );
		if ( 0 === strpos( $s, 'decimal' ) ) { return array( is_numeric( $v ) ? (string) $v : null, '%s' ); }
		if ( 0 === strpos( $s, 'tinyint' ) ) { return array( $v ? 1 : 0, '%d' ); }
		if ( 0 === strpos( $s, 'bigint' ) || 0 === strpos( $s, 'int' ) ) { return array( is_numeric( $v ) ? (int) $v : null, '%d' ); }
		if ( 0 === strpos( $s, 'date' ) ) { return array( sanitize_text_field( $v ), '%s' ); }
		if ( 0 === strpos( $s, 'longtext' ) || 0 === strpos( $s, 'text' ) ) { return array( is_scalar( $v ) ? sanitize_textarea_field( (string) $v ) : wp_json_encode( $v ), '%s' ); }
		return array( sanitize_text_field( (string) $v ), '%s' );
	}

	/** Validate + sanitize input against registry. Returns [data, formats] or WP_Error. */
	public static function prepare( $entity, $input, $partial = false ) {
		$d = self::def( $entity );
		if ( is_wp_error( $d ) ) { return $d; }
		$data = array(); $fmt = array(); $errors = array();
		foreach ( $d['columns'] as $col => $sql ) {
			if ( ! array_key_exists( $col, $input ) ) { continue; }
			$v = $input[ $col ];
			if ( ! empty( $d['json'] ) && in_array( $col, $d['json'], true ) && ( is_array( $v ) || is_object( $v ) ) ) { $v = wp_json_encode( $v ); }
			list( $val, $f ) = self::cast( $sql, $v );
			if ( null === $f ) { $data[ $col ] = null; $fmt[] = '%s'; } else { $data[ $col ] = $val; $fmt[] = $f; }
		}
		foreach ( $d['required'] as $req ) {
			if ( $partial && ! array_key_exists( $req, $input ) ) { continue; }
			if ( ! isset( $data[ $req ] ) || '' === $data[ $req ] ) { $errors[] = $req; }
		}
		if ( $errors ) {
			return new WP_Error( 'pdq_validation', 'Required field(s) missing: ' . implode( ', ', $errors ), array( 'status' => 422, 'fields' => $errors ) );
		}
		return array( $data, $fmt );
	}

	/** Decode JSON columns and strip hidden ones. */
	private static function shape( $d, $row ) {
		if ( ! $row ) { return $row; }
		foreach ( isset( $d['json'] ) ? $d['json'] : array() as $c ) {
			if ( isset( $row[ $c ] ) && is_string( $row[ $c ] ) && '' !== $row[ $c ] ) {
				$j = json_decode( $row[ $c ], true );
				if ( null !== $j ) { $row[ $c ] = $j; }
			}
		}
		foreach ( isset( $d['hidden'] ) ? $d['hidden'] : array() as $c ) { unset( $row[ $c ] ); }
		return $row;
	}

	/**
	 * Paginated list with search, filters, sorting.
	 * $args: page, per_page, search, orderby, order, filters[col=>val], trashed
	 */
	public static function list_rows( $entity, $args = array() ) {
		global $wpdb;
		$d = self::def( $entity ); if ( is_wp_error( $d ) ) { return $d; }
		$t = PDQ_Registry::table( $entity );
		$page = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per = min( 200, max( 1, (int) ( $args['per_page'] ?? 25 ) ) );
		$cols = self::all_cols( $d );
		$where = array(); $params = array();
		if ( empty( $d['no_workspace'] ) ) { $where[] = 'workspace_id = %d'; $params[] = self::workspace_id(); }
		$where[] = ! empty( $args['trashed'] ) ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search && ! empty( $d['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$or = array();
			foreach ( $d['search'] as $sc ) { $or[] = "$sc LIKE %s"; $params[] = $like; }
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		}
		foreach ( (array) ( $args['filters'] ?? array() ) as $fc => $fv ) {
			if ( '' === $fv || null === $fv || ! in_array( $fc, $cols, true ) ) { continue; }
			$where[] = "$fc = %s"; $params[] = $fv;
		}
		$orderby = in_array( $args['orderby'] ?? '', $cols, true ) ? $args['orderby'] : 'id';
		$order = 'ASC' === strtoupper( $args['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';
		$w = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $w", $params ) : "SELECT COUNT(*) FROM $t WHERE $w" );
		$qp = array_merge( $params, array( $per, ( $page - 1 ) * $per ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $w ORDER BY $orderby $order LIMIT %d OFFSET %d", $qp ), ARRAY_A );
		$rows = array_map( function ( $r ) use ( $d ) { return self::shape( $d, $r ); }, (array) $rows );
		return array( 'items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => (int) ceil( $total / $per ) );
	}

	public static function get( $entity, $id ) {
		global $wpdb;
		$d = self::def( $entity ); if ( is_wp_error( $d ) ) { return $d; }
		$t = PDQ_Registry::table( $entity );
		$sql = "SELECT * FROM $t WHERE id = %d";
		$p = array( (int) $id );
		if ( empty( $d['no_workspace'] ) ) { $sql .= ' AND workspace_id = %d'; $p[] = self::workspace_id(); }
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $p ), ARRAY_A );
		if ( ! $row ) { return new WP_Error( 'pdq_not_found', 'Record not found.', array( 'status' => 404 ) ); }
		return self::shape( $d, $row );
	}

	public static function create( $entity, $input ) {
		global $wpdb;
		$d = self::def( $entity ); if ( is_wp_error( $d ) ) { return $d; }
		if ( ! empty( $d['readonly'] ) ) { return new WP_Error( 'pdq_readonly', 'This entity is read-only.', array( 'status' => 405 ) ); }
		$p = self::prepare( $entity, $input ); if ( is_wp_error( $p ) ) { return $p; }
		list( $data, $fmt ) = $p;
		if ( empty( $d['no_workspace'] ) ) { $data['workspace_id'] = self::workspace_id(); $fmt[] = '%d'; }
		$data['owner_id'] = self::owner_id(); $fmt[] = '%d';
		$now = current_time( 'mysql' );
		$data['created_at'] = $now; $fmt[] = '%s'; $data['updated_at'] = $now; $fmt[] = '%s';
		if ( 'engineering_dimensions' === $entity ) { $data['row_hash'] = self::dim_hash( $data ); $fmt[] = '%s'; }
		$wpdb->suppress_errors( true );
		$ok = $wpdb->insert( PDQ_Registry::table( $entity ), $data, $fmt );
		$wpdb->suppress_errors( false );
		if ( false === $ok ) { return self::db_error( $wpdb->last_error ); }
		$id = (int) $wpdb->insert_id;
		if ( ! empty( $d['versioned'] ) ) { self::snapshot_version( $entity, $id ); }
		PDQ_Activity::log( 'create', $entity, $id, 'Created ' . $d['label'] . ' #' . $id );
		return self::get( $entity, $id );
	}

	public static function update( $entity, $id, $input ) {
		global $wpdb;
		$d = self::def( $entity ); if ( is_wp_error( $d ) ) { return $d; }
		if ( ! empty( $d['readonly'] ) ) { return new WP_Error( 'pdq_readonly', 'This entity is read-only.', array( 'status' => 405 ) ); }
		$cur = self::get( $entity, $id ); if ( is_wp_error( $cur ) ) { return $cur; }
		if ( 'documents' === $entity && ! empty( $cur['immutable'] ) ) {
			return new WP_Error( 'pdq_immutable', 'Issued documents are immutable. Create a new revision instead.', array( 'status' => 409 ) );
		}
		$p = self::prepare( $entity, $input, true ); if ( is_wp_error( $p ) ) { return $p; }
		list( $data, $fmt ) = $p;
		if ( ! $data ) { return $cur; }
		$data['updated_at'] = current_time( 'mysql' ); $fmt[] = '%s';
		if ( 'engineering_dimensions' === $entity ) { $data['row_hash'] = self::dim_hash( array_merge( $cur, $data ) ); $fmt[] = '%s'; }
		$wpdb->suppress_errors( true );
		$ok = $wpdb->update( PDQ_Registry::table( $entity ), $data, array( 'id' => (int) $id ), $fmt, array( '%d' ) );
		$wpdb->suppress_errors( false );
		if ( false === $ok ) { return self::db_error( $wpdb->last_error ); }
		if ( ! empty( $d['versioned'] ) ) { self::snapshot_version( $entity, (int) $id ); }
		PDQ_Activity::log( 'update', $entity, (int) $id, 'Updated ' . $d['label'] . ' #' . $id );
		return self::get( $entity, $id );
	}

	/** Soft delete (archive). */
	public static function trash( $entity, $id ) {
		global $wpdb;
		$d = self::def( $entity ); if ( is_wp_error( $d ) ) { return $d; }
		$cur = self::get( $entity, $id ); if ( is_wp_error( $cur ) ) { return $cur; }
		$wpdb->update( PDQ_Registry::table( $entity ), array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
		PDQ_Activity::log( 'archive', $entity, (int) $id, 'Archived ' . $d['label'] . ' #' . $id );
		return array( 'id' => (int) $id, 'archived' => true );
	}

	public static function restore( $entity, $id ) {
		global $wpdb;
		$d = self::def( $entity ); if ( is_wp_error( $d ) ) { return $d; }
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . PDQ_Registry::table( $entity ) . ' SET deleted_at = NULL WHERE id = %d', (int) $id ) );
		PDQ_Activity::log( 'restore', $entity, (int) $id, 'Restored ' . $d['label'] . ' #' . $id );
		return array( 'id' => (int) $id, 'archived' => false );
	}

	/** Permanent delete (only archived rows; UI must confirm). */
	public static function purge( $entity, $id ) {
		global $wpdb;
		$d = self::def( $entity ); if ( is_wp_error( $d ) ) { return $d; }
		$cur = self::get( $entity, $id ); if ( is_wp_error( $cur ) ) { return $cur; }
		if ( empty( $cur['deleted_at'] ) ) { return new WP_Error( 'pdq_not_archived', 'Archive the record before permanently deleting it.', array( 'status' => 409 ) ); }
		if ( 'documents' === $entity && ! empty( $cur['immutable'] ) ) { return new WP_Error( 'pdq_immutable', 'Issued documents cannot be permanently deleted.', array( 'status' => 409 ) ); }
		$wpdb->delete( PDQ_Registry::table( $entity ), array( 'id' => (int) $id ), array( '%d' ) );
		PDQ_Activity::log( 'delete', $entity, (int) $id, 'Permanently deleted ' . $d['label'] . ' #' . $id );
		return array( 'id' => (int) $id, 'deleted' => true );
	}

	/** Duplicate a record with optional overrides (e.g. new heat number). */
	public static function duplicate( $entity, $id, $overrides = array() ) {
		$d = self::def( $entity ); if ( is_wp_error( $d ) ) { return $d; }
		$cur = self::get( $entity, $id ); if ( is_wp_error( $cur ) ) { return $cur; }
		$copy = array();
		foreach ( array_keys( $d['columns'] ) as $c ) { if ( array_key_exists( $c, $cur ) ) { $copy[ $c ] = $cur[ $c ]; } }
		foreach ( (array) $overrides as $k => $v ) { if ( isset( $d['columns'][ $k ] ) ) { $copy[ $k ] = $v; } }
		if ( 'heat_numbers' === $entity ) {
			// Heat-specific test results must be re-verified before issuance.
			$copy['verification_status'] = 'unverified'; $copy['source_heat_id'] = (int) $id; $copy['verified_by'] = null; $copy['verified_at'] = null;
			if ( ( $copy['heat_number'] ?? '' ) === $cur['heat_number'] ) { $copy['heat_number'] = $cur['heat_number'] . '-COPY'; }
		}
		if ( 'documents' === $entity ) { $copy['status'] = 'draft'; $copy['immutable'] = 0; $copy['revision'] = 0; $copy['parent_id'] = (int) $id; $copy['doc_number'] = $cur['doc_number'] . '-COPY'; $copy['file_path'] = null; $copy['issued_at'] = null; }
		return self::create( $entity, $copy );
	}

	/** Dispatched qty on a PO item = sum of its non-archived dispatch items. */
	public static function recalc_dispatched( $po_item_id ) {
		global $wpdb; $po_item_id = (int) $po_item_id; if ( ! $po_item_id ) { return; }
		$sum = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(quantity),0) FROM ' . PDQ_Registry::table( 'dispatch_items' ) . ' WHERE po_item_id = %d AND deleted_at IS NULL', $po_item_id ) );
		$wpdb->update( PDQ_Registry::table( 'purchase_order_items' ), array( 'dispatched_qty' => $sum ), array( 'id' => $po_item_id ) );
	}

	public static function dim_hash( $row ) {
		$keys = array( 'item_code', 'category', 'item_type', 'material_grade', 'dimensional_standard', 'nps', 'schedule', 'pressure_class', 'outside_diameter', 'wall_thickness', 'center_to_end', 'end_to_end', 'flange_od', 'bolt_circle_dia' );
		$s = '';
		foreach ( $keys as $k ) { $s .= '|' . strtolower( trim( (string) ( $row[ $k ] ?? '' ) ) ); }
		return md5( $s );
	}

	private static function snapshot_version( $entity, $id ) {
		global $wpdb;
		$row = self::get( $entity, $id ); if ( is_wp_error( $row ) ) { return; }
		$n = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(version_no) FROM ' . $wpdb->prefix . 'pdq_record_versions WHERE entity = %s AND entity_id = %d', $entity, $id ) );
		$wpdb->insert( $wpdb->prefix . 'pdq_record_versions', array( 'workspace_id' => self::workspace_id(), 'entity' => $entity, 'entity_id' => $id, 'version_no' => $n, 'snapshot' => wp_json_encode( $row ), 'changed_by' => self::owner_id(), 'created_at' => current_time( 'mysql' ) ), array( '%d', '%s', '%d', '%d', '%s', '%d', '%s' ) );
	}

	public static function versions( $entity, $id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, version_no, snapshot, changed_by, created_at FROM ' . $wpdb->prefix . 'pdq_record_versions WHERE entity = %s AND entity_id = %d AND workspace_id = %d ORDER BY version_no DESC', $entity, (int) $id, self::workspace_id() ), ARRAY_A );
		foreach ( $rows as &$r ) { $r['snapshot'] = json_decode( $r['snapshot'], true ); }
		return $rows;
	}

	/** Duplicate detection for engineering records: returns groups with identical hash. */
	public static function find_duplicates( $limit = 100 ) {
		global $wpdb;
		$t = PDQ_Registry::table( 'engineering_dimensions' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT row_hash, COUNT(*) c, GROUP_CONCAT(id) ids FROM $t WHERE workspace_id = %d AND deleted_at IS NULL AND row_hash IS NOT NULL GROUP BY row_hash HAVING c > 1 LIMIT %d", self::workspace_id(), (int) $limit ), ARRAY_A );
		return $rows;
	}

	private static function db_error( $msg ) {
		if ( false !== stripos( $msg, 'Duplicate entry' ) ) {
			return new WP_Error( 'pdq_duplicate', 'A record with the same unique value already exists.', array( 'status' => 409 ) );
		}
		return new WP_Error( 'pdq_db', 'Database error. Check the Activity/Error log.', array( 'status' => 500 ) );
	}
}
