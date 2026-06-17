<?php
/**
 * Unit tests for WpCapabilityProvider — gates access by WordPress capability slug.
 *
 * Covers identity (get_id, get_label, is_available), get_options (dynamic
 * discovery from wp_roles(), dedup across roles, alphabetical ordering, skip
 * of falsy caps, filter override), and user_has_access (empty options
 * short-circuit, ANY-match via user_can(), filter-driven negative path).
 *
 * Brain Monkey mocks WordPress functions; no WordPress install required.
 */

namespace WPBoilerplate\AccessControl\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPBoilerplate\AccessControl\WpCapabilityProvider;

final class WpCapabilityProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function provider(): WpCapabilityProvider {
		return new WpCapabilityProvider();
	}

	private function fake_roles( array $roles ): object {
		$obj        = new \stdClass();
		$obj->roles = $roles;
		return $obj;
	}

	// -------------------------------------------------------------------------
	// Identity / metadata
	// -------------------------------------------------------------------------

	public function test_get_id_returns_wp_capability(): void {
		$this->assertSame( 'wp_capability', $this->provider()->get_id() );
	}

	public function test_get_label_returns_wordpress_capability_string(): void {
		$this->assertSame( 'WordPress Capability', $this->provider()->get_label() );
	}

	public function test_is_available_returns_true_by_default(): void {
		$this->assertTrue( $this->provider()->is_available() );
	}

	// -------------------------------------------------------------------------
	// get_options()
	// -------------------------------------------------------------------------

	public function test_get_options_collects_caps_from_all_roles(): void {
		Functions\when( 'wp_roles' )->justReturn(
			$this->fake_roles(
				array(
					'administrator' => array(
						'capabilities' => array(
							'manage_options'  => true,
							'install_plugins' => true,
						),
					),
					'editor'        => array(
						'capabilities' => array(
							'edit_posts'        => true,
							'edit_others_posts' => true,
						),
					),
				)
			)
		);
		Filters\expectApplied( 'wpb_access_control_wp_capability_options' )->once()->andReturnFirstArg();

		$options = $this->provider()->get_options();
		$ids     = array_column( $options, 'id' );

		$this->assertContains( 'manage_options', $ids );
		$this->assertContains( 'install_plugins', $ids );
		$this->assertContains( 'edit_posts', $ids );
		$this->assertContains( 'edit_others_posts', $ids );
		$this->assertCount( 4, $options );
	}

	public function test_get_options_deduplicates_caps_shared_across_roles(): void {
		Functions\when( 'wp_roles' )->justReturn(
			$this->fake_roles(
				array(
					'editor' => array(
						'capabilities' => array(
							'edit_posts' => true,
							'read'       => true,
						),
					),
					'author' => array(
						'capabilities' => array(
							'edit_posts' => true,
							'read'       => true,
						),
					),
				)
			)
		);
		Filters\expectApplied( 'wpb_access_control_wp_capability_options' )->andReturnFirstArg();

		$options = $this->provider()->get_options();
		$ids     = array_column( $options, 'id' );

		$this->assertSame( array( 'edit_posts', 'read' ), $ids );
	}

	public function test_get_options_sorts_caps_alphabetically(): void {
		Functions\when( 'wp_roles' )->justReturn(
			$this->fake_roles(
				array(
					'role' => array(
						'capabilities' => array(
							'zeta'  => true,
							'alpha' => true,
							'mu'    => true,
						),
					),
				)
			)
		);
		Filters\expectApplied( 'wpb_access_control_wp_capability_options' )->andReturnFirstArg();

		$ids = array_column( $this->provider()->get_options(), 'id' );

		$this->assertSame( array( 'alpha', 'mu', 'zeta' ), $ids );
	}

	public function test_get_options_skips_capabilities_with_falsy_values(): void {
		Functions\when( 'wp_roles' )->justReturn(
			$this->fake_roles(
				array(
					'role' => array(
						'capabilities' => array(
							'edit_posts'        => true,
							'manage_options'    => false,
							'unfiltered_upload' => 0,
							'install_plugins'   => 1,
						),
					),
				)
			)
		);
		Filters\expectApplied( 'wpb_access_control_wp_capability_options' )->andReturnFirstArg();

		$ids = array_column( $this->provider()->get_options(), 'id' );

		$this->assertContains( 'edit_posts', $ids );
		$this->assertContains( 'install_plugins', $ids );
		$this->assertNotContains( 'manage_options', $ids );
		$this->assertNotContains( 'unfiltered_upload', $ids );
	}

	public function test_get_options_returns_id_equals_label(): void {
		Functions\when( 'wp_roles' )->justReturn(
			$this->fake_roles(
				array(
					'role' => array(
						'capabilities' => array( 'edit_posts' => true ),
					),
				)
			)
		);
		Filters\expectApplied( 'wpb_access_control_wp_capability_options' )->andReturnFirstArg();

		$this->assertSame(
			array( array( 'id' => 'edit_posts', 'label' => 'edit_posts' ) ),
			$this->provider()->get_options()
		);
	}

	public function test_get_options_returns_empty_when_no_roles_exist(): void {
		Functions\when( 'wp_roles' )->justReturn( $this->fake_roles( array() ) );
		Filters\expectApplied( 'wpb_access_control_wp_capability_options' )->andReturnFirstArg();

		$this->assertSame( array(), $this->provider()->get_options() );
	}

	public function test_get_options_handles_role_without_capabilities_key(): void {
		Functions\when( 'wp_roles' )->justReturn(
			$this->fake_roles(
				array(
					'broken' => array( 'name' => 'Broken' ),
					'editor' => array( 'capabilities' => array( 'edit_posts' => true ) ),
				)
			)
		);
		Filters\expectApplied( 'wpb_access_control_wp_capability_options' )->andReturnFirstArg();

		$ids = array_column( $this->provider()->get_options(), 'id' );

		$this->assertSame( array( 'edit_posts' ), $ids );
	}

	public function test_get_options_filter_can_replace_the_entire_list(): void {
		Functions\when( 'wp_roles' )->justReturn(
			$this->fake_roles(
				array(
					'role' => array( 'capabilities' => array( 'edit_posts' => true ) ),
				)
			)
		);
		Filters\expectApplied( 'wpb_access_control_wp_capability_options' )
			->once()
			->andReturn( array( array( 'id' => 'override', 'label' => 'override' ) ) );

		$this->assertSame(
			array( array( 'id' => 'override', 'label' => 'override' ) ),
			$this->provider()->get_options()
		);
	}

	public function test_get_options_casts_non_array_filter_return_back_to_array(): void {
		Functions\when( 'wp_roles' )->justReturn( $this->fake_roles( array() ) );
		Filters\expectApplied( 'wpb_access_control_wp_capability_options' )->andReturn( null );

		$this->assertSame( array(), $this->provider()->get_options() );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — empty options short-circuit
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_selected_options_empty(): void {
		Functions\expect( 'user_can' )->never();
		Filters\expectApplied( 'wpb_access_control_wp_capability_has_access' )->never();

		$this->assertFalse( $this->provider()->user_has_access( 1, array() ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — match (true cases)
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_true_when_user_can_returns_true_for_single_cap(): void {
		Functions\expect( 'user_can' )->once()->with( 7, 'edit_posts' )->andReturn( true );
		Filters\expectApplied( 'wpb_access_control_wp_capability_has_access' )->never();

		$this->assertTrue( $this->provider()->user_has_access( 7, array( 'edit_posts' ) ) );
	}

	public function test_user_has_access_returns_true_when_user_can_matches_any_selected_cap(): void {
		Functions\expect( 'user_can' )->once()->with( 7, 'edit_posts' )->andReturn( false );
		Functions\expect( 'user_can' )->once()->with( 7, 'manage_options' )->andReturn( true );
		Filters\expectApplied( 'wpb_access_control_wp_capability_has_access' )->never();

		$this->assertTrue( $this->provider()->user_has_access( 7, array( 'edit_posts', 'manage_options' ) ) );
	}

	public function test_user_has_access_short_circuits_on_first_matching_cap(): void {
		// First cap matches — `user_can` for later caps must not be called.
		Functions\expect( 'user_can' )->once()->with( 7, 'edit_posts' )->andReturn( true );
		Filters\expectApplied( 'wpb_access_control_wp_capability_has_access' )->never();

		$this->assertTrue( $this->provider()->user_has_access( 7, array( 'edit_posts', 'manage_options' ) ) );
	}

	public function test_user_has_access_skips_empty_string_cap_entries(): void {
		// Empty-string entry is silently skipped (no user_can call) but does
		// not count as a match — fall through to the next cap.
		Functions\expect( 'user_can' )->once()->with( 7, 'edit_posts' )->andReturn( true );
		Filters\expectApplied( 'wpb_access_control_wp_capability_has_access' )->never();

		$this->assertTrue( $this->provider()->user_has_access( 7, array( '', 'edit_posts' ) ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — no match (filter-driven cases)
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_no_cap_matches_and_filter_keeps_false(): void {
		Functions\expect( 'user_can' )->once()->with( 7, 'edit_posts' )->andReturn( false );
		Functions\expect( 'user_can' )->once()->with( 7, 'manage_options' )->andReturn( false );
		Filters\expectApplied( 'wpb_access_control_wp_capability_has_access' )
			->once()
			->with( false, 7, array( 'edit_posts', 'manage_options' ) )
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 7, array( 'edit_posts', 'manage_options' ) ) );
	}

	public function test_user_has_access_filter_can_override_false_to_true(): void {
		Functions\expect( 'user_can' )->once()->with( 7, 'edit_posts' )->andReturn( false );
		Filters\expectApplied( 'wpb_access_control_wp_capability_has_access' )
			->once()
			->with( false, 7, array( 'edit_posts' ) )
			->andReturn( true );

		$this->assertTrue( $this->provider()->user_has_access( 7, array( 'edit_posts' ) ) );
	}

	public function test_user_has_access_filter_truthy_value_is_cast_to_bool(): void {
		Functions\expect( 'user_can' )->once()->andReturn( false );
		Filters\expectApplied( 'wpb_access_control_wp_capability_has_access' )
			->once()
			->andReturn( 1 );

		$this->assertTrue( $this->provider()->user_has_access( 1, array( 'edit_posts' ) ) );
	}

	public function test_user_has_access_filter_falsy_value_is_cast_to_bool(): void {
		Functions\expect( 'user_can' )->once()->andReturn( false );
		Filters\expectApplied( 'wpb_access_control_wp_capability_has_access' )
			->once()
			->andReturn( '' );

		$this->assertFalse( $this->provider()->user_has_access( 1, array( 'edit_posts' ) ) );
	}

	public function test_user_has_access_passes_correct_args_to_filter_on_no_match(): void {
		Functions\expect( 'user_can' )->once()->with( 42, 'edit_posts' )->andReturn( false );
		Functions\expect( 'user_can' )->once()->with( 42, 'manage_options' )->andReturn( false );
		Filters\expectApplied( 'wpb_access_control_wp_capability_has_access' )
			->once()
			->with( false, 42, array( 'edit_posts', 'manage_options' ) )
			->andReturn( false );

		$this->provider()->user_has_access( 42, array( 'edit_posts', 'manage_options' ) );
		$this->assertTrue( true ); // Mockery verifies the with() expectation.
	}
}
