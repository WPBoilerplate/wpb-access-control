<?php
/**
 * Unit tests for RulesController.
 */

namespace {
	if ( ! class_exists( 'WP_REST_Controller' ) ) {
		class WP_REST_Controller {}
	}

	if ( ! class_exists( 'WP_REST_Request' ) ) {
		class WP_REST_Request {
			private $params;
			private $method;

			public function __construct( array $params = array(), string $method = 'GET' ) {
				$this->params = $params;
				$this->method = $method;
			}

			public function get_param( string $key ) {
				return $this->params[ $key ] ?? null;
			}

			public function get_method(): string {
				return $this->method;
			}
		}
	}

	if ( ! class_exists( 'WP_REST_Response' ) ) {
		class WP_REST_Response {
			private $data;

			public function __construct( $data ) {
				$this->data = $data;
			}

			public function get_data() {
				return $this->data;
			}
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private $code;
			private $message;
			private $data;

			public function __construct( string $code = '', string $message = '', $data = array() ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			public function get_error_data() {
				return $this->data;
			}
		}
	}

	if ( ! class_exists( 'WP_REST_Server' ) ) {
		class WP_REST_Server {
			const READABLE  = 'GET';
			const EDITABLE  = 'POST, PUT, PATCH';
			const DELETABLE = 'DELETE';
		}
	}
}

namespace WPBoilerplate\AccessControl\Tests\Unit\RestApi {

	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Filters;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use WP_Error;
	use WP_REST_Request;
	use WP_REST_Response;
	use WPBoilerplate\AccessControl\AbstractProvider;
	use WPBoilerplate\AccessControl\AccessControlManager;
	use WPBoilerplate\AccessControl\Database\Rule\RuleQuery;
	use WPBoilerplate\AccessControl\Database\Rule\RuleTable;
	use WPBoilerplate\AccessControl\RestApi\RulesController;
	use WPBoilerplate\AccessControl\WpUserProvider;

	final class RulesControllerTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Functions\when( '__' )->returnArg();
			Functions\when( 'is_wp_error' )->alias( static fn( $value ) => $value instanceof WP_Error );
			Functions\when( 'rest_ensure_response' )->alias(
				static fn( $value ) => $value instanceof WP_REST_Response ? $value : new WP_REST_Response( $value )
			);
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		private function request( array $params = array(), string $method = 'GET' ): WP_REST_Request {
			return new WP_REST_Request( $params, $method );
		}

		private function query_mock(): RuleQuery {
			return $this->getMockBuilder( RuleQuery::class )
				->disableOriginalConstructor()
				->onlyMethods( array( 'get_rule', 'set_rule', 'clear_rule', 'purge_namespace' ) )
				->getMock();
		}

		private function manager_mock( ?RuleQuery $query = null, array $providers = array() ): AccessControlManager {
			$manager = $this->getMockBuilder( AccessControlManager::class )
				->disableOriginalConstructor()
				->onlyMethods( array( 'get_query', 'get_providers' ) )
				->getMock();

			if ( null !== $query ) {
				$manager->method( 'get_query' )->willReturn( $query );
			}

			$manager->method( 'get_providers' )->willReturn( $providers );

			return $manager;
		}

		private function provider( string $id, string $label, array $options, bool $available ): AbstractProvider {
			$provider = $this->getMockBuilder( AbstractProvider::class )
				->onlyMethods( array( 'get_id', 'get_label', 'get_options', 'user_has_access', 'is_available' ) )
				->getMock();

			$provider->method( 'get_id' )->willReturn( $id );
			$provider->method( 'get_label' )->willReturn( $label );
			$provider->method( 'get_options' )->willReturn( $options );
			$provider->method( 'is_available' )->willReturn( $available );

			return $provider;
		}

		public function test_check_permission_allows_when_current_user_can_manage_options(): void {
			Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
			Filters\expectApplied( 'wpb_access_control_rest_permission' )
				->once()
				->with( true, \Mockery::type( WP_REST_Request::class ) )
				->andReturn( true );

			$controller = new RulesController( $this->manager_mock() );

			$this->assertTrue( $controller->check_permission( $this->request() ) );
		}

		public function test_check_permission_returns_wp_error_when_filter_denies(): void {
			Functions\expect( 'current_user_can' )->once()->andReturn( true );
			Functions\expect( 'rest_authorization_required_code' )->once()->andReturn( 403 );
			Filters\expectApplied( 'wpb_access_control_rest_permission' )->once()->andReturn( false );

			$controller = new RulesController( $this->manager_mock() );
			$result     = $controller->check_permission( $this->request() );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'rest_forbidden', $result->get_error_code() );
			$this->assertSame( array( 'status' => 403 ), $result->get_error_data() );
		}

		public function test_get_rule_returns_rule_from_query(): void {
			$query = $this->query_mock();
			$query->expects( $this->once() )
				->method( 'get_rule' )
				->with( 'ns', 'resource/key' )
				->willReturn( array( 'key' => 'wp_role', 'value' => array( 'editor' ) ) );

			$controller = new RulesController( $this->manager_mock( $query ) );
			$response   = $controller->get_rule( $this->request( array( 'namespace' => 'ns', 'key' => 'resource/key' ) ) );

			$this->assertSame( array( 'key' => 'wp_role', 'value' => array( 'editor' ) ), $response->get_data() );
		}

		public function test_set_rule_authorizes_writes_saves_rule_and_fires_action(): void {
			Functions\expect( 'get_current_user_id' )->once()->andReturn( 9 );
			Filters\expectApplied( 'wpb_access_control_can_save' )
				->once()
				->with( true, 'ns', 'key', 9 )
				->andReturn( true );
			Actions\expectDone( 'wpb_access_control_saved' )
				->once()
				->with( 'ns', 'key', 'wp_role', array( 'editor' ), 9 );

			$query = $this->query_mock();
			$query->expects( $this->once() )
				->method( 'set_rule' )
				->with( 'ns', 'key', 'wp_role', array( 'editor' ) )
				->willReturn( true );
			$query->expects( $this->once() )
				->method( 'get_rule' )
				->with( 'ns', 'key' )
				->willReturn( array( 'key' => 'wp_role', 'value' => array( 'editor' ) ) );

			$controller = new RulesController( $this->manager_mock( $query ) );
			$response   = $controller->set_rule(
				$this->request(
					array(
						'namespace'  => 'ns',
						'key'        => 'key',
						'ac_key'     => 'wp_role',
						'ac_options' => array( 'editor' ),
					),
					'PUT'
				)
			);

			$this->assertSame(
				array(
					'success' => true,
					'rule'    => array( 'key' => 'wp_role', 'value' => array( 'editor' ) ),
				),
				$response->get_data()
			);
		}

		public function test_set_rule_returns_forbidden_when_save_filter_denies(): void {
			Functions\expect( 'get_current_user_id' )->once()->andReturn( 9 );
			Filters\expectApplied( 'wpb_access_control_can_save' )->once()->andReturn( false );

			$query = $this->query_mock();
			$query->expects( $this->never() )->method( 'set_rule' );

			$controller = new RulesController( $this->manager_mock( $query ) );
			$result     = $controller->set_rule( $this->request( array( 'namespace' => 'ns', 'key' => 'key' ), 'PUT' ) );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'wpb_ac_forbidden', $result->get_error_code() );
			$this->assertSame( array( 'status' => 403 ), $result->get_error_data() );
		}

		public function test_set_rule_returns_error_when_query_save_fails(): void {
			Functions\expect( 'get_current_user_id' )->once()->andReturn( 9 );
			Filters\expectApplied( 'wpb_access_control_can_save' )->once()->andReturn( true );
			Actions\expectDone( 'wpb_access_control_saved' )->never();

			$query = $this->query_mock();
			$query->expects( $this->once() )->method( 'set_rule' )->willReturn( false );

			$controller = new RulesController( $this->manager_mock( $query ) );
			$result     = $controller->set_rule( $this->request( array( 'namespace' => 'ns', 'key' => 'key' ), 'PUT' ) );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'wpb_ac_save_failed', $result->get_error_code() );
		}

		public function test_clear_rule_authorizes_clears_and_fires_saved_action(): void {
			Functions\expect( 'get_current_user_id' )->once()->andReturn( 9 );
			Filters\expectApplied( 'wpb_access_control_can_save' )->once()->andReturn( true );
			Actions\expectDone( 'wpb_access_control_saved' )
				->once()
				->with( 'ns', 'key', '', array(), 9 );

			$query = $this->query_mock();
			$query->expects( $this->once() )->method( 'clear_rule' )->with( 'ns', 'key' );

			$controller = new RulesController( $this->manager_mock( $query ) );
			$response   = $controller->clear_rule( $this->request( array( 'namespace' => 'ns', 'key' => 'key' ), 'DELETE' ) );

			$this->assertSame( array( 'success' => true ), $response->get_data() );
		}

		public function test_purge_namespace_authorizes_with_wildcard_key_and_returns_deleted_count(): void {
			Functions\expect( 'get_current_user_id' )->once()->andReturn( 9 );
			Filters\expectApplied( 'wpb_access_control_can_save' )
				->once()
				->with( true, 'ns', '*', 9 )
				->andReturn( true );

			$query = $this->query_mock();
			$query->expects( $this->once() )->method( 'purge_namespace' )->with( 'ns' )->willReturn( 3 );

			$controller = new RulesController( $this->manager_mock( $query ) );
			$response   = $controller->purge_namespace( $this->request( array( 'namespace' => 'ns' ), 'DELETE' ) );

			$this->assertSame( array( 'deleted' => 3 ), $response->get_data() );
		}

		public function test_get_providers_serializes_registered_provider_metadata(): void {
			$provider = $this->provider(
				'custom',
				'Custom',
				array( array( 'id' => 'option', 'label' => 'Option' ) ),
				false
			);

			$controller = new RulesController( $this->manager_mock( null, array( 'custom' => $provider ) ) );
			$response   = $controller->get_providers( $this->request() );

			$this->assertSame(
				array(
					array(
						'id'        => 'custom',
						'label'     => 'Custom',
						'options'   => array( array( 'id' => 'option', 'label' => 'Option' ) ),
						'available' => false,
					),
				),
				$response->get_data()
			);
		}

		public function test_search_users_delegates_to_user_provider_helper(): void {
			Functions\when( 'sanitize_text_field' )->returnArg();
			Functions\expect( 'get_users' )
				->once()
				->with(
					\Mockery::on(
						static function ( array $args ): bool {
							return '*alice*' === $args['search'] && 5 === $args['number'];
						}
					)
				)
				->andReturn(
					array(
						(object) array(
							'ID'           => 12,
							'user_login'   => 'alice',
							'user_email'   => 'alice@example.com',
							'display_name' => 'Alice',
						),
					)
				);

			$controller = new RulesController( $this->manager_mock() );
			$response   = $controller->search_users( $this->request( array( 'search' => 'alice', 'limit' => 5 ) ) );

			$this->assertSame(
				array(
					array(
						'id'           => '12',
						'login'        => 'alice',
						'email'        => 'alice@example.com',
						'display_name' => 'Alice',
					),
				),
				$response->get_data()
			);
		}

		public function test_validate_namespace_rejects_empty_and_too_long_values(): void {
			$controller = new RulesController( $this->manager_mock() );

			$this->assertInstanceOf( WP_Error::class, $controller->validate_namespace( '' ) );
			$this->assertInstanceOf( WP_Error::class, $controller->validate_namespace( str_repeat( 'a', RuleTable::NAMESPACE_LENGTH + 1 ) ) );
			$this->assertTrue( $controller->validate_namespace( str_repeat( 'a', RuleTable::NAMESPACE_LENGTH ) ) );
		}

		public function test_validate_key_rejects_empty_and_too_long_values(): void {
			$controller = new RulesController( $this->manager_mock() );

			$this->assertInstanceOf( WP_Error::class, $controller->validate_key( '' ) );
			$this->assertInstanceOf( WP_Error::class, $controller->validate_key( str_repeat( 'a', RuleTable::KEY_LENGTH + 1 ) ) );
			$this->assertTrue( $controller->validate_key( str_repeat( 'a', RuleTable::KEY_LENGTH ) ) );
		}
	}
}
