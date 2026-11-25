<?php
/**
 * Table registry: single source of truth for schema + generic CRUD + UI metadata.
 *
 * Each entity: table suffix, label, columns [name => SQL type], list (columns shown),
 * search (columns searched), required, uniques, indexes, fks (col => entity), json (cols stored as JSON), group.
 *
 * @package PipeDocIQ
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class PDQ_Registry {

	const COMMON_PREFIX = 'pdq_';

	/** @return array<string,array> */
	public static function entities() {
		static $e = null;
		if ( null !== $e ) { return $e; }
		$v = 'varchar(190)'; $t = 'text'; $d = 'decimal(18,4)'; $i = 'bigint(20) unsigned'; $dt = 'date'; $int = 'int(11)';
		$e = array(
			'workspaces' => array( 'label' => 'Workspaces', 'group' => 'system', 'columns' => array( 'name' => "$v NOT NULL", 'slug' => "$v NOT NULL", 'status' => "varchar(20) NOT NULL DEFAULT 'active'" ), 'list' => array( 'name', 'slug', 'status' ), 'search' => array( 'name' ), 'required' => array( 'name', 'slug' ), 'uniques' => array( 'slug' => array( 'slug' ) ), 'no_workspace' => true ),

			'company_profiles' => array( 'label' => 'Company Profiles', 'group' => 'company', 'columns' => array(
				'company_name' => "$v NOT NULL", 'registered_address' => $t, 'factory_address' => $t, 'gstin' => 'varchar(20)', 'pan' => 'varchar(12)',
				'contact_number' => 'varchar(60)', 'email' => $v, 'website' => $v, 'logo_path' => 'varchar(255)', 'letterhead_path' => 'varchar(255)',
				'signatory_name' => $v, 'signatory_designation' => $v, 'stamp_path' => 'varchar(255)', 'signature_path' => 'varchar(255)',
				'qc_stamp_path' => 'varchar(255)', 'inspection_stamp_path' => 'varchar(255)', 'additional_info' => $t, 'is_default' => 'tinyint(1) NOT NULL DEFAULT 0' ),
				'list' => array( 'company_name', 'gstin', 'contact_number', 'email' ), 'search' => array( 'company_name', 'gstin', 'pan', 'email' ), 'required' => array( 'company_name' ), 'indexes' => array( 'gstin' => array( 'gstin' ) ) ),

			'customers' => self::party( 'Customers', 'parties' ),
			'suppliers' => self::party( 'Suppliers', 'parties' ),
			'manufacturers' => self::party( 'Manufacturers', 'parties' ),

			'master_items' => array( 'label' => 'Master Items', 'group' => 'master', 'columns' => array( 'list_type' => "varchar(30) NOT NULL", 'name' => "$v NOT NULL", 'code' => 'varchar(60)', 'address' => $t, 'details' => $t, 'is_active' => 'tinyint(1) NOT NULL DEFAULT 1' ),
				'list' => array( 'name', 'code', 'address', 'is_active' ), 'search' => array( 'name', 'code' ), 'required' => array( 'list_type', 'name' ), 'indexes' => array( 'lt' => array( 'list_type', 'name' ) ) ),

			'product_categories' => array( 'label' => 'Product Categories', 'group' => 'engineering', 'columns' => array( 'name' => "$v NOT NULL", 'slug' => "$v NOT NULL", 'family' => 'varchar(60)', 'applicable_fields' => 'longtext', 'is_active' => 'tinyint(1) NOT NULL DEFAULT 1' ),
				'list' => array( 'name', 'family', 'is_active' ), 'search' => array( 'name', 'family' ), 'required' => array( 'name', 'slug' ), 'uniques' => array( 'slug' => array( 'workspace_id', 'slug' ) ), 'json' => array( 'applicable_fields' ) ),
			'product_types' => array( 'label' => 'Product Types', 'group' => 'engineering', 'columns' => array( 'category_id' => "$i NOT NULL", 'name' => "$v NOT NULL", 'abbreviation' => 'varchar(30)', 'description' => $t ),
				'list' => array( 'name', 'abbreviation', 'category_id' ), 'search' => array( 'name', 'abbreviation' ), 'required' => array( 'category_id', 'name' ), 'fks' => array( 'category_id' => 'product_categories' ), 'indexes' => array( 'category_id' => array( 'category_id' ), 'abbr' => array( 'abbreviation' ) ) ),
			'material_grades' => array( 'label' => 'Material Grades', 'group' => 'engineering', 'columns' => array( 'grade' => "$v NOT NULL", 'material_type' => 'varchar(60)', 'specification' => $v, 'description' => $t, 'density_kg_m3' => $d, 'density_source' => $v ),
				'list' => array( 'grade', 'material_type', 'specification', 'density_kg_m3' ), 'search' => array( 'grade', 'specification', 'material_type' ), 'required' => array( 'grade' ), 'uniques' => array( 'grade' => array( 'workspace_id', 'grade', 'specification' ) ) ),
			'engineering_standards' => array( 'label' => 'Engineering Standards', 'group' => 'engineering', 'columns' => array( 'code' => "$v NOT NULL", 'title' => $v, 'revision' => 'varchar(60)', 'body' => 'varchar(60)', 'scope' => $t ),
				'list' => array( 'code', 'title', 'revision', 'body' ), 'search' => array( 'code', 'title' ), 'required' => array( 'code' ), 'uniques' => array( 'code' => array( 'workspace_id', 'code', 'revision' ) ) ),

			'engineering_dimensions' => array( 'label' => 'Engineering Database', 'group' => 'engineering', 'columns' => array(
				'item_code' => "$v NOT NULL", 'category_id' => $i, 'category' => 'varchar(120)', 'item_type' => 'varchar(120)', 'item_description' => $t,
				'material_type' => 'varchar(60)', 'material_grade' => 'varchar(120)', 'manufacturing_standard' => 'varchar(120)', 'dimensional_standard' => 'varchar(120)',
				'nps' => 'varchar(20)', 'nb' => 'varchar(20)', 'dn' => 'varchar(20)', 'size_inch' => 'varchar(30)',
				'outside_diameter' => $d, 'inside_diameter' => $d, 'wall_thickness' => $d, 'schedule' => 'varchar(20)', 'pressure_class' => 'varchar(20)', 'lbs_rating' => 'varchar(20)',
				'center_to_end' => $d, 'end_to_end' => $d, 'radius' => $d, 'flange_od' => $d, 'flange_thickness' => $d, 'bolt_circle_dia' => $d, 'bolt_hole_dia' => $d,
				'bolt_holes' => $int, 'raised_face_dia' => $d, 'raised_face_height' => $d, 'hub_dia' => $d, 'hub_length' => $d, 'bore_dia' => $d,
				'unit_weight' => $d, 'weight_per_meter' => $d, 'tolerances' => $t, 'extra_fields' => 'longtext',
				'unit' => "varchar(10) NOT NULL DEFAULT 'mm'", 'source_reference' => $v, 'standard_revision' => 'varchar(60)',
				'verification_status' => "varchar(20) NOT NULL DEFAULT 'unverified'", 'row_hash' => 'char(32)' ),
				'list' => array( 'item_code', 'category', 'item_type', 'nps', 'schedule', 'pressure_class', 'material_grade', 'verification_status' ),
				'search' => array( 'item_code', 'category', 'item_type', 'item_description', 'material_grade', 'nps', 'nb', 'schedule', 'pressure_class', 'dimensional_standard' ),
				'required' => array( 'item_code' ), 'json' => array( 'extra_fields' ),
				'indexes' => array( 'item_code' => array( 'item_code' ), 'cat_size' => array( 'category', 'nps', 'schedule' ), 'class' => array( 'pressure_class' ), 'row_hash' => array( 'workspace_id', 'row_hash' ), 'verif' => array( 'verification_status' ) ),
				'versioned' => true ),
			'material_properties' => array( 'label' => 'Material Properties', 'group' => 'engineering', 'columns' => array( 'grade_id' => $i, 'property_name' => "$v NOT NULL", 'property_type' => 'varchar(30)', 'min_value' => $d, 'max_value' => $d, 'unit' => 'varchar(20)', 'standard_ref' => $v, 'source_reference' => $v, 'verification_status' => "varchar(20) NOT NULL DEFAULT 'unverified'" ),
				'list' => array( 'property_name', 'property_type', 'min_value', 'max_value', 'unit', 'verification_status' ), 'search' => array( 'property_name', 'standard_ref' ), 'required' => array( 'property_name' ), 'fks' => array( 'grade_id' => 'material_grades' ), 'indexes' => array( 'grade_id' => array( 'grade_id' ) ) ),

			'purchase_orders' => array( 'label' => 'Purchase Orders', 'group' => 'commercial', 'columns' => array( 'po_number' => "$v NOT NULL", 'po_type' => "varchar(20) NOT NULL DEFAULT 'purchase'", 'po_date' => $dt, 'customer_id' => $i, 'project' => $v, 'consignee' => $v, 'delivery_location' => $v, 'inspection_agency' => $v, 'inspection_requirements' => $t, 'status' => "varchar(20) NOT NULL DEFAULT 'open'", 'notes' => $t ),
				'list' => array( 'po_number', 'po_type', 'po_date', 'customer_id', 'status' ), 'search' => array( 'po_number', 'project', 'consignee' ), 'required' => array( 'po_number' ), 'fks' => array( 'customer_id' => 'customers' ), 'uniques' => array( 'po' => array( 'workspace_id', 'po_type', 'po_number' ) ), 'indexes' => array( 'customer' => array( 'customer_id' ), 'po_date' => array( 'po_date' ) ) ),
			'purchase_order_items' => array( 'label' => 'PO Items', 'group' => 'commercial', 'columns' => array( 'po_id' => "$i NOT NULL", 'line_no' => $int, 'material_code' => 'varchar(60)', 'item_description' => $t, 'material_grade' => 'varchar(120)', 'specification' => $v, 'ordered_qty' => $d, 'uom' => "varchar(20) DEFAULT 'Nos'", 'dispatched_qty' => "decimal(18,4) NOT NULL DEFAULT 0", 'unit_weight' => $d, 'inspection_requirements' => $t, 'dimension_id' => $i ),
				'list' => array( 'line_no', 'material_code', 'item_description', 'ordered_qty', 'dispatched_qty', 'uom' ), 'search' => array( 'item_description', 'material_code' ), 'required' => array( 'po_id' ), 'fks' => array( 'po_id' => 'purchase_orders', 'dimension_id' => 'engineering_dimensions' ), 'indexes' => array( 'po_id' => array( 'po_id' ) ) ),

			'heat_numbers' => array( 'label' => 'Heat Numbers', 'group' => 'quality', 'columns' => array( 'heat_number' => "$v NOT NULL", 'batch_number' => 'varchar(60)', 'lot_number' => 'varchar(60)', 'manufacturer_id' => $i, 'raw_material' => $t, 'finished_product' => $t, 'material_grade' => 'varchar(120)', 'specification' => $v, 'manufacturing_process' => $v, 'mtc_number' => 'varchar(80)', 'mtc_path' => 'varchar(255)', 'quantity' => $d, 'inspection_date' => $dt, 'po_id' => $i, 'supporting_files' => 'longtext', 'remarks' => $t, 'verification_status' => "varchar(20) NOT NULL DEFAULT 'unverified'", 'verified_by' => $i, 'verified_at' => 'datetime', 'source_heat_id' => $i ),
				'list' => array( 'heat_number', 'batch_number', 'material_grade', 'quantity', 'inspection_date', 'verification_status' ), 'search' => array( 'heat_number', 'batch_number', 'lot_number', 'material_grade', 'mtc_number' ), 'required' => array( 'heat_number' ), 'json' => array( 'supporting_files' ), 'fks' => array( 'manufacturer_id' => 'manufacturers', 'po_id' => 'purchase_orders' ), 'indexes' => array( 'heat' => array( 'heat_number' ), 'po_id' => array( 'po_id' ) ) ),
			'chemical_tests' => array( 'label' => 'Chemical Tests', 'group' => 'quality', 'columns' => array( 'heat_id' => "$i NOT NULL", 'element' => 'varchar(20) NOT NULL', 'actual_value' => 'decimal(10,5)', 'min_limit' => 'decimal(10,5)', 'max_limit' => 'decimal(10,5)', 'unit' => "varchar(10) DEFAULT '%'", 'standard_ref' => $v, 'result' => 'varchar(10)' ),
				'list' => array( 'heat_id', 'element', 'actual_value', 'min_limit', 'max_limit', 'result' ), 'search' => array( 'element' ), 'required' => array( 'heat_id', 'element' ), 'fks' => array( 'heat_id' => 'heat_numbers' ), 'uniques' => array( 'heat_el' => array( 'heat_id', 'element' ) ) ),
			'mechanical_tests' => array( 'label' => 'Mechanical Tests', 'group' => 'quality', 'columns' => array( 'heat_id' => "$i NOT NULL", 'property' => 'varchar(60) NOT NULL', 'actual_value' => $d, 'min_limit' => $d, 'max_limit' => $d, 'unit' => 'varchar(20)', 'test_method' => $v, 'result' => 'varchar(10)' ),
				'list' => array( 'heat_id', 'property', 'actual_value', 'min_limit', 'max_limit', 'unit', 'result' ), 'search' => array( 'property' ), 'required' => array( 'heat_id', 'property' ), 'fks' => array( 'heat_id' => 'heat_numbers' ), 'indexes' => array( 'heat_id' => array( 'heat_id' ) ) ),
			'inspection_results' => array( 'label' => 'Quality Inspection', 'group' => 'quality', 'columns' => array( 'heat_id' => $i, 'po_item_id' => $i, 'inspection_type' => 'varchar(60) NOT NULL', 'parameter' => $v, 'nominal_value' => $d, 'tol_min' => $d, 'tol_max' => $d, 'actual_value' => $d, 'unit' => 'varchar(20)', 'result' => 'varchar(10)', 'inspected_by' => $v, 'inspection_date' => $dt, 'remarks' => $t ),
				'list' => array( 'inspection_type', 'parameter', 'nominal_value', 'actual_value', 'result', 'inspection_date' ), 'search' => array( 'inspection_type', 'parameter', 'inspected_by' ), 'required' => array( 'inspection_type' ), 'fks' => array( 'heat_id' => 'heat_numbers', 'po_item_id' => 'purchase_order_items' ), 'indexes' => array( 'heat_id' => array( 'heat_id' ), 'type' => array( 'inspection_type' ) ) ),

			'dispatch_batches' => array( 'label' => 'Dispatch Management', 'group' => 'commercial', 'columns' => array( 'batch_no' => "$v NOT NULL", 'po_id' => $i, 'dispatch_date' => $dt, 'vehicle_no' => 'varchar(40)', 'transporter' => $v, 'lr_number' => 'varchar(60)', 'destination' => $v, 'packing_weight' => $d, 'status' => "varchar(20) NOT NULL DEFAULT 'draft'", 'remarks' => $t ),
				'list' => array( 'batch_no', 'po_id', 'dispatch_date', 'vehicle_no', 'status' ), 'search' => array( 'batch_no', 'vehicle_no', 'lr_number' ), 'required' => array( 'batch_no' ), 'fks' => array( 'po_id' => 'purchase_orders' ), 'uniques' => array( 'batch' => array( 'workspace_id', 'batch_no' ) ) ),
			'dispatch_items' => array( 'label' => 'Dispatch Items', 'group' => 'commercial', 'columns' => array( 'batch_id' => "$i NOT NULL", 'po_item_id' => $i, 'heat_id' => $i, 'quantity' => $d, 'net_weight' => $d, 'gross_weight' => $d, 'packages' => $int, 'package_type' => 'varchar(40)' ),
				'list' => array( 'batch_id', 'po_item_id', 'heat_id', 'quantity', 'net_weight' ), 'search' => array( 'package_type' ), 'required' => array( 'batch_id' ), 'fks' => array( 'batch_id' => 'dispatch_batches', 'po_item_id' => 'purchase_order_items', 'heat_id' => 'heat_numbers' ), 'indexes' => array( 'batch_id' => array( 'batch_id' ) ) ),

			'templates' => array( 'label' => 'Templates', 'group' => 'documents', 'columns' => array( 'name' => "$v NOT NULL", 'doc_type' => 'varchar(80) NOT NULL', 'category' => 'varchar(40)', 'file_type' => 'varchar(10)', 'orientation' => "varchar(10) DEFAULT 'portrait'", 'current_version' => "int(11) NOT NULL DEFAULT 1", 'status' => "varchar(20) NOT NULL DEFAULT 'draft'", 'description' => $t ),
				'list' => array( 'name', 'doc_type', 'file_type', 'current_version', 'status' ), 'search' => array( 'name', 'doc_type' ), 'required' => array( 'name', 'doc_type' ), 'indexes' => array( 'doc_type' => array( 'doc_type' ) ) ),
			'template_versions' => array( 'label' => 'Template Versions', 'group' => 'documents', 'columns' => array( 'template_id' => "$i NOT NULL", 'version_no' => 'int(11) NOT NULL', 'file_path' => 'varchar(255)', 'file_hash' => 'char(64)', 'analysis' => 'longtext', 'page_settings' => 'longtext', 'warnings' => 'longtext', 'mapping_verified' => 'tinyint(1) NOT NULL DEFAULT 0' ),
				'list' => array( 'template_id', 'version_no', 'mapping_verified' ), 'search' => array(), 'required' => array( 'template_id', 'version_no' ), 'json' => array( 'analysis', 'page_settings', 'warnings' ), 'fks' => array( 'template_id' => 'templates' ), 'uniques' => array( 'tv' => array( 'template_id', 'version_no' ) ) ),
			'template_field_mappings' => array( 'label' => 'Template Field Mappings', 'group' => 'documents', 'columns' => array( 'template_version_id' => "$i NOT NULL", 'placeholder' => "$v NOT NULL", 'location' => $v, 'data_source' => $v, 'is_repeating' => 'tinyint(1) NOT NULL DEFAULT 0', 'format' => 'varchar(60)', 'mapped_by' => "varchar(10) DEFAULT 'manual'" ),
				'list' => array( 'placeholder', 'location', 'data_source', 'is_repeating', 'mapped_by' ), 'search' => array( 'placeholder', 'data_source' ), 'required' => array( 'template_version_id', 'placeholder' ), 'fks' => array( 'template_version_id' => 'template_versions' ), 'indexes' => array( 'tv' => array( 'template_version_id' ) ) ),
			'signature_assets' => array( 'label' => 'Signature & Stamp Assets', 'group' => 'documents', 'columns' => array( 'company_id' => $i, 'asset_type' => "varchar(30) NOT NULL", 'label' => $v, 'file_path' => 'varchar(255)', 'default_position' => 'longtext', 'is_active' => 'tinyint(1) NOT NULL DEFAULT 1' ),
				'list' => array( 'asset_type', 'label', 'company_id', 'is_active' ), 'search' => array( 'label', 'asset_type' ), 'required' => array( 'asset_type' ), 'json' => array( 'default_position' ), 'fks' => array( 'company_id' => 'company_profiles' ) ),

			'documents' => array( 'label' => 'Document Archive', 'group' => 'documents', 'columns' => array( 'doc_number' => "$v NOT NULL", 'doc_type' => 'varchar(80) NOT NULL', 'template_id' => $i, 'template_version' => $int, 'company_id' => $i, 'customer_id' => $i, 'po_id' => $i, 'heat_id' => $i, 'batch_id' => $i, 'revision' => 'int(11) NOT NULL DEFAULT 0', 'status' => "varchar(20) NOT NULL DEFAULT 'draft'", 'data_snapshot' => 'longtext', 'file_path' => 'varchar(255)', 'file_hash' => 'char(64)', 'immutable' => 'tinyint(1) NOT NULL DEFAULT 0', 'parent_id' => $i, 'issued_at' => 'datetime' ),
				'list' => array( 'doc_number', 'doc_type', 'revision', 'status', 'company_id', 'po_id', 'created_at' ), 'search' => array( 'doc_number', 'doc_type' ), 'required' => array( 'doc_number', 'doc_type' ), 'json' => array( 'data_snapshot' ), 'fks' => array( 'template_id' => 'templates', 'company_id' => 'company_profiles', 'customer_id' => 'customers', 'po_id' => 'purchase_orders', 'heat_id' => 'heat_numbers', 'batch_id' => 'dispatch_batches' ),
				'uniques' => array( 'docrev' => array( 'workspace_id', 'doc_number', 'revision' ) ), 'indexes' => array( 'type' => array( 'doc_type' ), 'status' => array( 'status' ), 'po_id' => array( 'po_id' ), 'heat_id' => array( 'heat_id' ) ) ),
			'document_items' => array( 'label' => 'Document Items', 'group' => 'documents', 'columns' => array( 'document_id' => "$i NOT NULL", 'line_no' => $int, 'po_item_id' => $i, 'dimension_id' => $i, 'heat_id' => $i, 'quantity' => $d, 'item_data' => 'longtext' ),
				'list' => array( 'document_id', 'line_no', 'quantity' ), 'search' => array(), 'required' => array( 'document_id' ), 'json' => array( 'item_data' ), 'fks' => array( 'document_id' => 'documents' ), 'indexes' => array( 'document_id' => array( 'document_id' ) ) ),
			'document_revisions' => array( 'label' => 'Document Revisions', 'group' => 'documents', 'columns' => array( 'document_id' => "$i NOT NULL", 'revision' => 'int(11) NOT NULL', 'change_note' => $t, 'snapshot' => 'longtext', 'file_path' => 'varchar(255)', 'file_hash' => 'char(64)' ),
				'list' => array( 'document_id', 'revision', 'change_note' ), 'search' => array(), 'required' => array( 'document_id', 'revision' ), 'json' => array( 'snapshot' ), 'fks' => array( 'document_id' => 'documents' ), 'uniques' => array( 'rev' => array( 'document_id', 'revision' ) ) ),
			'document_attachments' => array( 'label' => 'Document Attachments', 'group' => 'documents', 'columns' => array( 'document_id' => "$i NOT NULL", 'file_name' => $v, 'file_path' => 'varchar(255)', 'mime' => 'varchar(100)', 'size_bytes' => $i ),
				'list' => array( 'document_id', 'file_name', 'mime', 'size_bytes' ), 'search' => array( 'file_name' ), 'required' => array( 'document_id' ), 'fks' => array( 'document_id' => 'documents' ) ),
			'generation_jobs' => array( 'label' => 'Generation Jobs', 'group' => 'documents', 'columns' => array( 'job_type' => 'varchar(40) NOT NULL', 'status' => "varchar(20) NOT NULL DEFAULT 'queued'", 'params' => 'longtext', 'progress' => 'int(11) NOT NULL DEFAULT 0', 'result' => 'longtext', 'error' => $t, 'started_at' => 'datetime', 'finished_at' => 'datetime' ),
				'list' => array( 'job_type', 'status', 'progress', 'created_at' ), 'search' => array( 'job_type' ), 'required' => array( 'job_type' ), 'json' => array( 'params', 'result' ), 'indexes' => array( 'status' => array( 'status' ) ) ),

			'ai_settings' => array( 'label' => 'AI Settings', 'group' => 'system', 'columns' => array( 'user_id' => $i, 'api_key_enc' => $t, 'key_hint' => 'varchar(12)', 'model' => $v, 'enabled' => 'tinyint(1) NOT NULL DEFAULT 0', 'consent_given' => 'tinyint(1) NOT NULL DEFAULT 0', 'usage_requests' => 'bigint(20) NOT NULL DEFAULT 0', 'usage_errors' => 'bigint(20) NOT NULL DEFAULT 0', 'last_error' => $t ),
				'list' => array( 'model', 'enabled' ), 'search' => array(), 'required' => array(), 'hidden' => array( 'api_key_enc' ), 'internal' => true ),
			'activity_logs' => array( 'label' => 'Activity Logs', 'group' => 'system', 'columns' => array( 'user_id' => $i, 'action' => 'varchar(60) NOT NULL', 'entity' => 'varchar(60)', 'entity_id' => $i, 'summary' => $v, 'detail' => 'longtext', 'ip' => 'varchar(45)' ),
				'list' => array( 'created_at', 'action', 'entity', 'entity_id', 'summary' ), 'search' => array( 'action', 'entity', 'summary' ), 'required' => array( 'action' ), 'readonly' => true, 'indexes' => array( 'entity' => array( 'entity', 'entity_id' ), 'action' => array( 'action' ) ) ),
			'application_settings' => array( 'label' => 'Application Settings', 'group' => 'system', 'columns' => array( 'setting_key' => 'varchar(120) NOT NULL', 'setting_value' => 'longtext' ),
				'list' => array( 'setting_key', 'setting_value' ), 'search' => array( 'setting_key' ), 'required' => array( 'setting_key' ), 'uniques' => array( 'k' => array( 'workspace_id', 'setting_key' ) ) ),
		);
		return $e;
	}

	/** Party master (customers / suppliers / manufacturers share a shape). */
	private static function party( $label, $group ) {
		$v = 'varchar(190)';
		return array( 'label' => $label, 'group' => 'master', 'columns' => array( 'name' => "$v NOT NULL", 'code' => 'varchar(40)', 'address' => 'text', 'city' => 'varchar(80)', 'state' => 'varchar(80)', 'country' => "varchar(60) DEFAULT 'India'", 'gstin' => 'varchar(20)', 'pan' => 'varchar(12)', 'contact_person' => $v, 'phone' => 'varchar(60)', 'email' => $v, 'notes' => 'text', 'is_active' => 'tinyint(1) NOT NULL DEFAULT 1' ),
			'list' => array( 'name', 'code', 'city', 'gstin', 'phone', 'email' ), 'search' => array( 'name', 'code', 'gstin', 'city', 'email' ), 'required' => array( 'name' ), 'indexes' => array( 'name' => array( 'name' ), 'gstin' => array( 'gstin' ) ) );
	}

	public static function get( $key ) {
		$all = self::entities();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function table( $key ) {
		global $wpdb;
		return $wpdb->prefix . self::COMMON_PREFIX . $key;
	}

	/** Build dbDelta CREATE TABLE statements. */
	public static function schema_sql( $prefix, $charset_collate ) {
		$out = array();
		foreach ( self::entities() as $key => $def ) {
			$cols = array( 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT' );
			if ( empty( $def['no_workspace'] ) ) {
				$cols[] = 'workspace_id bigint(20) unsigned NOT NULL DEFAULT 1';
			}
			$cols[] = 'owner_id bigint(20) unsigned NOT NULL DEFAULT 0';
			foreach ( $def['columns'] as $c => $sql ) { $cols[] = "$c $sql"; }
			$cols[] = 'created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP';
			$cols[] = 'updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP';
			$cols[] = 'deleted_at datetime DEFAULT NULL';
			$cols[] = 'PRIMARY KEY  (id)';
			if ( empty( $def['no_workspace'] ) ) { $cols[] = 'KEY workspace_id (workspace_id)'; }
			foreach ( isset( $def['uniques'] ) ? $def['uniques'] : array() as $n => $cs ) { $cols[] = 'UNIQUE KEY u_' . $n . ' (' . implode( ',', $cs ) . ')'; }
			foreach ( isset( $def['indexes'] ) ? $def['indexes'] : array() as $n => $cs ) { $cols[] = 'KEY i_' . $n . ' (' . implode( ',', $cs ) . ')'; }
			$out[ $key ] = "CREATE TABLE {$prefix}" . self::COMMON_PREFIX . "{$key} (\n" . implode( ",\n", $cols ) . "\n) $charset_collate;";
		}
		// Version history for engineering dimensions (supports "version history").
		$out['record_versions'] = "CREATE TABLE {$prefix}pdq_record_versions (\n id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n workspace_id bigint(20) unsigned NOT NULL DEFAULT 1,\n entity varchar(60) NOT NULL,\n entity_id bigint(20) unsigned NOT NULL,\n version_no int(11) NOT NULL,\n snapshot longtext,\n changed_by bigint(20) unsigned NOT NULL DEFAULT 0,\n created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,\n PRIMARY KEY  (id),\n KEY i_entity (entity, entity_id)\n) $charset_collate;";
		return $out;
	}
}
