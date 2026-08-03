<?php
/**
 * Unit tests for AccessControlManager.
 */

namespace WPBoilerplate\AccessControl\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPBoilerplate\AccessControl\AbstractProvider;
use WPBoilerplate\AccessControl\AccessControlManager;
use WPBoilerplate\AccessControl\Database\Rule\RuleQuery;

final class AccessControlManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_manager( array $rule, array $providers = array() ): AccessControlManager {
		$query = $this->getMockBuilder( RuleQuery::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_rule' ) )
			->getMock();

		$query->method( 'get_rule' )->willReturn( $rule );

		$reflection = new \ReflectionClass( AccessControlManager::class );
		$manager    = $reflection->newInstanceWithoutConstructor();

		$query_property = $reflection->getProperty( 'query' );
		$query_property->setAccessible( true );
		$query_property->setValue( $manager, $query );

		$providers_property = $reflection->getProperty( 'providers' );
		$providers_property->setAccessible( true );
		$providers_property->setValue( $manager, $providers );

		return $manager;
	}

	private function provider( string $id, bool $allowed ): AbstractProvider {
		$provider = $this->getMockBuilder( AbstractProvider::class )
			->onlyMethods( array( 'get_id', 'get_label', 'get_options', 'user_has_access' ) )
			->getMock();

		$provider->method( 'get_id' )->willReturn( $id );
		$provider->method( 'get_label' )->willReturn( $id );
		$provider->method( 'get_options' )->willReturn( array() );
		$provider->method( 'user_has_access' )->willReturn( $allowed );

		return $provider;
	}

	public function test_user_has_access_allows_when_no_rule_is_configured(): void {
		Functions\expect( 'user_can' )->never();
		Actions\expectDone( 'wpb_access_control_denied' )->never();

		$manager = $this->make_manager( array( 'key' => '', 'value' => array() ) );

		$this->assertTrue( $manager->user_has_access( 0, 'ns', 'key' ) );
	}

	public function test_user_has_access_allows_everyone_before_admin_or_auth_checks(): void {
		Functions\expect( 'user_can' )->never();
		Actions\expectDone( 'wpb_access_control_denied' )->never();

		$manager = $this->make_manager( array( 'key' => AccessControlManager::TYPE_EVERYONE, 'value' => array() ) );

		$this->assertTrue( $manager->user_has_access( 0, 'ns', 'key' ) );
	}

	public function test_user_has_access_allows_manage_options_user_for_restricted_rule(): void {
		Functions\expect( 'user_can' )
			->once()
			->with( 7, 'manage_options' )
			->andReturn( true );
		Actions\expectDone( 'wpb_access_control_denied' )->never();

		$manager = $this->make_manager( array( 'key' => 'missing_provider', 'value' => array( 'x' ) ) );

		$this->assertTrue( $manager->user_has_access( 7, 'ns', 'key' ) );
	}

	public function test_user_has_access_denies_logged_out_user_for_restricted_rule(): void {
		Functions\expect( 'user_can' )->never();
		Actions\expectDone( 'wpb_access_control_denied' )
			->once()
			->with( 0, 'ns', 'key', 'wp_role', array( 'editor' ) );

		$manager = $this->make_manager( array( 'key' => 'wp_role', 'value' => array( 'editor' ) ) );

		$this->assertFalse( $manager->user_has_access( 0, 'ns', 'key' ) );
	}

	public function test_user_has_access_denies_when_provider_is_not_registered(): void {
		Functions\expect( 'user_can' )->once()->with( 5, 'manage_options' )->andReturn( false );
		Actions\expectDone( 'wpb_access_control_denied' )
			->once()
			->with( 5, 'ns', 'key', 'unknown', array( 'option' ) );

		$manager = $this->make_manager( array( 'key' => 'unknown', 'value' => array( 'option' ) ) );

		$this->assertFalse( $manager->user_has_access( 5, 'ns', 'key' ) );
	}

	public function test_user_has_access_returns_provider_allow_result(): void {
		Functions\expect( 'user_can' )->once()->with( 5, 'manage_options' )->andReturn( false );
		Actions\expectDone( 'wpb_access_control_denied' )->never();

		$provider = $this->provider( 'custom', true );
		$manager  = $this->make_manager(
			array( 'key' => 'custom', 'value' => array( 'option' ) ),
			array( 'custom' => $provider )
		);

		$this->assertTrue( $manager->user_has_access( 5, 'ns', 'key' ) );
	}

	public function test_user_has_access_fires_denied_action_when_provider_denies(): void {
		Functions\expect( 'user_can' )->once()->with( 5, 'manage_options' )->andReturn( false );
		Actions\expectDone( 'wpb_access_control_denied' )
			->once()
			->with( 5, 'ns', 'key', 'custom', array( 'option' ) );

		$provider = $this->provider( 'custom', false );
		$manager  = $this->make_manager(
			array( 'key' => 'custom', 'value' => array( 'option' ) ),
			array( 'custom' => $provider )
		);

		$this->assertFalse( $manager->user_has_access( 5, 'ns', 'key' ) );
	}

	public function test_load_providers_indexes_only_provider_instances_by_id(): void {
		Functions\when( '__' )->returnArg();
		Filters\expectApplied( 'custom_provider_filter' )
			->once()
			->andReturn(
				array(
					$this->provider( 'first', true ),
					'not-a-provider',
					$this->provider( 'second', false ),
				)
			);

		$manager = $this->make_bare_manager( 'custom_provider_filter', 'my_plugin' );

		$manager->load_providers();

		$this->assertSame( array( 'first', 'second' ), array_keys( $manager->get_providers() ) );
		$this->assertSame( 'first', $manager->get_provider( 'first' )->get_id() );
		$this->assertNull( $manager->get_provider( 'missing' ) );
	}

	public function test_load_providers_applies_global_filter_before_consumer_filter(): void {
		Functions\when( '__' )->returnArg();

		$global   = $this->provider( 'from_global', true );
		$consumer = $this->provider( 'from_consumer', true );

		// The global filter fires first with the initial defaults + the
		// consumer's table slug. It appends `from_global`.
		Filters\expectApplied( 'wpb_access_control_register_providers' )
			->once()
			->with( \Mockery::type( 'array' ), 'my_plugin' )
			->andReturnUsing(
				static function ( array $providers ) use ( $global ) {
					$providers[] = $global;
					return $providers;
				}
			);

		// The consumer-specific filter fires next and receives whatever the
		// global filter returned. It appends `from_consumer`.
		Filters\expectApplied( 'my_plugin_providers' )
			->once()
			->with(
				\Mockery::on(
					static function ( array $providers ) use ( $global ): bool {
						// Must include the provider added by the global filter.
						foreach ( $providers as $p ) {
							if ( $p === $global ) {
								return true;
							}
						}
						return false;
					}
				)
			)
			->andReturnUsing(
				static function ( array $providers ) use ( $consumer ) {
					$providers[] = $consumer;
					return $providers;
				}
			);

		$manager = $this->make_bare_manager( 'my_plugin_providers', 'my_plugin' );
		$manager->load_providers();

		$ids = array_keys( $manager->get_providers() );
		// Global-added provider must appear before consumer-added one — proves
		// the global filter ran first and its result flowed into the consumer filter.
		$global_pos   = array_search( 'from_global', $ids, true );
		$consumer_pos = array_search( 'from_consumer', $ids, true );

		$this->assertNotFalse( $global_pos, 'Global-registered provider must be indexed' );
		$this->assertNotFalse( $consumer_pos, 'Consumer-registered provider must be indexed' );
		$this->assertLessThan( $consumer_pos, $global_pos, 'Global filter must fire before consumer filter' );
	}

	/**
	 * Build an AccessControlManager instance without invoking the constructor
	 * (which would spin up a real RuleQuery + BerlinDB table). Only the
	 * `providers_filter`, `table_slug`, and empty `providers` state are set,
	 * which is enough to exercise load_providers().
	 */
	private function make_bare_manager( string $providers_filter, string $table_slug ): AccessControlManager {
		$reflection = new \ReflectionClass( AccessControlManager::class );
		$manager    = $reflection->newInstanceWithoutConstructor();

		foreach (
			array(
				'providers_filter' => $providers_filter,
				'table_slug'       => $table_slug,
				'providers'        => array(),
			) as $property => $value
		) {
			$prop = $reflection->getProperty( $property );
			$prop->setAccessible( true );
			$prop->setValue( $manager, $value );
		}

		return $manager;
	}
}
