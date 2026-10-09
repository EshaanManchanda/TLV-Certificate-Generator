<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/GuidelineScanner.php';

/**
 * Checks the plugin against the WordPress.org Detailed Plugin Guidelines and the review
 * team's rules, as listed in wporg-guidelines.json. Automated checks run here; manual and
 * external ones are reported as skipped with what to do.
 */
final class WpOrgGuidelinesTest extends TestCase {

	private static ?GuidelineScanner $scanner = null;

	private static function config(): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/wporg-guidelines.json' ), true, 512, JSON_THROW_ON_ERROR );
	}

	public static function checks(): array {
		$cases = array();
		foreach ( self::config()['guidelines'] as $g ) {
			foreach ( $g['checks'] as $check ) {
				$cases[ "{$g['id']} {$check['id']}" ] = array( $g['id'], $g['title'], $check );
			}
		}
		return $cases;
	}

	/** @dataProvider checks */
	public function test_guideline( string $id, string $title, array $check ): void {
		if ( 'automated' !== $check['type'] ) {
			$this->markTestSkipped( "{$check['type']}: {$check['description']}" );
		}
		self::$scanner ??= new GuidelineScanner( dirname( __DIR__, 2 ), self::config() );
		$this->assertTrue( method_exists( self::$scanner, $check['id'] ), "No scanner method for check '{$check['id']}'" );

		$violations = self::$scanner->{$check['id']}();
		$this->assertSame(
			array(),
			$violations,
			"$id $title — {$check['description']}\n" . count( $violations ) . " violation(s):\n  " . implode( "\n  ", array_slice( $violations, 0, 60 ) ) . ( count( $violations ) > 60 ? "\n  …" : '' )
		);
	}

	public function test_every_guideline_is_covered(): void {
		$ids = array_column( self::config()['guidelines'], 'id' );
		foreach ( range( 1, 18 ) as $n ) {
			$this->assertContains( "G$n", $ids, "Guideline $n is missing from wporg-guidelines.json" );
		}
		foreach ( self::config()['guidelines'] as $g ) {
			$this->assertNotEmpty( $g['checks'], "{$g['id']} has no checks" );
		}
	}
}
