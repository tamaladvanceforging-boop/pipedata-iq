<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Built-in taxonomies (structure only — no engineering values). */
class PDQ_Meta {
	public static function categories() {
		return array( 'Seamless Pipes', 'Welded Pipes', '45° Elbows', '90° Elbows', '180° Return Bends', 'Long Radius Elbows', 'Short Radius Elbows', 'Equal Tees', 'Reducing Tees', 'Concentric Reducers', 'Eccentric Reducers', 'End Caps', 'Stub Ends', 'Couplings', 'Half Couplings', 'Unions', 'Nipples', 'Hex Nipples', 'Plugs', 'Bushings', 'Olets', 'Socket Weld Fittings', 'Threaded Fittings', 'Butt Weld Fittings', 'Weld Neck Flanges', 'Slip-On Flanges', 'Blind Flanges', 'Socket Weld Flanges', 'Threaded Flanges', 'Lap Joint Flanges', 'Spectacle Blinds', 'Spades and Spacers' );
	}
	public static function doc_types() {
		return array(
			'quality'  => array( 'Test Certificate (TC)', 'Mill Test Certificate (MTC)', 'Material Test Certificate', 'Chemical Composition Report', 'Mechanical Test Report', 'Dimensional Inspection Report', 'Visual Inspection Report', 'Hardness Test Report', 'Hydrostatic Test Report', 'PMI Report', 'Ultrasonic Test Report', 'Radiography Test Report', 'Dye Penetrant Test Report', 'Magnetic Particle Test Report', 'Heat Treatment Report', 'Material Traceability Report', 'Certificate of Conformity', 'Final Inspection Report', 'Inspection and Test Plan', 'Quality Assurance Plan' ),
			'dispatch' => array( 'Packing List', 'Delivery Challan', 'Tax Invoice Draft', 'Dispatch Advice', 'Dispatch Checklist', 'Material Dispatch Report', 'Warranty Certificate', 'Guarantee Certificate', 'Weight Certificate', 'Certificate of Origin Draft', 'Material Dispatch Clearance Request', 'Inspection Release Note Request', 'Covering Letter', 'Bulk Dispatch Document Set' ),
		);
	}
	public static function placeholders() {
		return array_merge( array( 'company_name', 'company_address', 'customer_name', 'po_number', 'po_date', 'certificate_number', 'certificate_date', 'item_description', 'material_grade', 'heat_number', 'quantity', 'outside_diameter', 'wall_thickness', 'schedule', 'pressure_class', 'unit_weight', 'total_weight', 'chemical_composition', 'mechanical_properties', 'company_stamp', 'authorized_signature' ), self::nabl_placeholders() );
	}

	/** Per-field placeholders for chemical elements and mechanical properties (filled from NABL lab data). */
	public static function nabl_placeholders() {
		$out = array();
		foreach ( array( 'C', 'Mn', 'Si', 'S', 'P', 'Cr', 'Ni', 'Mo', 'Cu', 'V', 'Nb', 'Cb', 'Ti', 'Al', 'B', 'N' ) as $el ) { $out[] = 'chem_' . $el; $out[] = 'chem_' . $el . '_min'; $out[] = 'chem_' . $el . '_max'; }
		foreach ( array( 'yield_strength', 'tensile_strength', 'elongation', 'reduction_of_area', 'hardness', 'impact_energy_avg', 'impact_energy_1', 'impact_energy_2', 'impact_energy_3' ) as $p ) { $out[] = 'mech_' . $p; $out[] = 'mech_' . $p . '_min'; $out[] = 'mech_' . $p . '_max'; }
		return $out;
	}
	public static function nav() {
		return array(
			array( 'id' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'LayoutDashboard' ),
			array( 'id' => 'engineering_dimensions', 'label' => 'Engineering Database', 'icon' => 'Database' ),
			array( 'id' => 'master', 'label' => 'Master Data', 'icon' => 'Layers' ),
			array( 'id' => 'company_profiles', 'label' => 'Company Profiles', 'icon' => 'Building2' ),
			array( 'id' => 'customers', 'label' => 'Customers', 'icon' => 'Users' ),
			array( 'id' => 'suppliers', 'label' => 'Suppliers', 'icon' => 'Truck' ),
			array( 'id' => 'purchase_orders', 'label' => 'Purchase Orders', 'icon' => 'ClipboardList' ),
			array( 'id' => 'material_grades', 'label' => 'Material Management', 'icon' => 'Boxes' ),
			array( 'id' => 'heat_numbers', 'label' => 'Heat Number Management', 'icon' => 'Flame' ),
			array( 'id' => 'tc', 'label' => 'Test Certificates', 'icon' => 'BadgeCheck' ),
			array( 'id' => 'inspection_results', 'label' => 'Quality Inspection', 'icon' => 'Microscope' ),
			array( 'id' => 'dispatch_batches', 'label' => 'Dispatch Management', 'icon' => 'PackageCheck' ),
			array( 'id' => 'templates', 'label' => 'Template Studio', 'icon' => 'FileCog' ),
			array( 'id' => 'generator', 'label' => 'Document Generator', 'icon' => 'FilePlus2' ),
			array( 'id' => 'documents', 'label' => 'Document Archive', 'icon' => 'Archive' ),
			array( 'id' => 'ai_assistant', 'label' => 'AI Assistant', 'icon' => 'Sparkles' ),
			array( 'id' => 'ai_settings', 'label' => 'Gemini AI Settings', 'icon' => 'KeyRound' ),
			array( 'id' => 'import_export', 'label' => 'Import / Export', 'icon' => 'ArrowLeftRight' ),
			array( 'id' => 'activity_logs', 'label' => 'Activity Logs', 'icon' => 'History' ),
			array( 'id' => 'backup', 'label' => 'Backup / Restore', 'icon' => 'HardDriveDownload' ),
			array( 'id' => 'settings', 'label' => 'Application Settings', 'icon' => 'Settings' ),
			array( 'id' => 'help', 'label' => 'Help & Documentation', 'icon' => 'LifeBuoy' ),
		);
	}
}
