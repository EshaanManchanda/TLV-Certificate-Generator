<?php
/**
 * Confirms the public verify endpoint (registered by both SerialNumberService
 * and the legacy CertificateGenerator_Serial_Number_Generator) doesn't leak anything beyond a
 * valid/invalid flag for an unknown serial.
 */
class RestApiPermissionTest extends WP_UnitTestCase {

	public function test_modern_verify_endpoint_returns_404_with_no_data_for_unknown_serial(): void {
		$service = new \CertificateGenerator\Services\SerialNumberService();
		$request = new WP_REST_Request( 'GET', '/certificate-generator/v1/verify/DOES-NOT-EXIST' );
		$request->set_param( 'serial', 'DOES-NOT-EXIST' );

		$response = $service->api_verify( $request );

		$this->assertSame( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['valid'] );
		$this->assertNull( $data['data'] );
	}

	public function test_legacy_verify_endpoint_returns_404_with_no_data_for_unknown_serial(): void {
		$service = CertificateGenerator_Serial_Number_Generator::get_instance();
		$request = new WP_REST_Request( 'GET', '/certificate-generator/v1/verify/DOES-NOT-EXIST' );
		$request->set_param( 'serial', 'DOES-NOT-EXIST' );

		$response = $service->api_verify( $request );

		$this->assertSame( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['valid'] );
		$this->assertNull( $data['data'] );
	}
}
