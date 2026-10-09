<?php
/**
 * Maps feature areas to the test classes that cover them, purely so the
 * Test Center admin page (src/Admin/Pages/TestCenterPage.php) can group raw
 * PHPUnit results into something readable. Pure data — add a line here
 * whenever a new test class is added, no runner logic lives in this file.
 */
return array(
	'Field Mapping'        => array( 'FieldManagerTest' ),
	'Database & Migrations' => array( 'MigrationRunnerTest', 'DualMigrationFallbackTest' ),
	'Serial Numbers'       => array( 'SerialNumberServiceTest', 'DualSerialGeneratorConsistencyTest' ),
	'Certificate Pipeline' => array( 'CertificatePipelineTest', 'GeneratePdfFromRowTest' ),
	'Bulk Operations'      => array( 'BulkDownloadSqlTest', 'BulkSendZipTest' ),
	'Email'                => array( 'EmailTemplateMappingTest', 'StudentEmailAjaxTest' ),
	'Templates & Fonts'    => array( 'FontManagerTest', 'TemplateFieldCountTest', 'TemplatePositionBoundsTest' ),
	'Feature Flags'        => array( 'FeatureToggleTest' ),
	'Security'             => array( 'NonceTest', 'PermissionTest', 'RestApiPermissionTest', 'InputSanitizationTest' ),
	'Admin CRUD'           => array( 'StudentsCrudTest', 'TeachersCrudTest', 'SchoolsCrudTest', 'TemplatesCrudTest', 'EventsCrudTest' ),
	'Shortcodes'           => array( 'StudentSearchShortcodeTest' ),
	'Dev Tools'            => array( 'TestCenterOllamaSuggestionsTest' ),
	'Smoke'                => array( 'SmokeTest' ),
);
