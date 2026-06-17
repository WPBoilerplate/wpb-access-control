<?php
/**
 * Unit tests for BuddyBossProfileTypeProvider — gates access by BuddyBoss
 * profile type (member type).
 *
 * Covers identity (get_id, get_label), availability gating (is_available,
 * get_options + user_has_access short-circuits when BuddyBoss is inactive),
 * the get_options pipeline (label fallback, alphabetical sort, filter
 * override, non-array API result), and user_has_access (empty options,
 * user with no types, intersect match, multi-type match, filter override).
 *
 * Brain Monkey mocks WordPress and BuddyBoss functions; no WordPress install
 * required. The provider's `is_available()` short-circuits are exercised via
 * a partial mock to avoid runtime fiddling with PHP's `function_exists()`.
 */

namespace WPBoilerplate\AccessControl\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPBoilerplate\AccessControl\BuddyBossProfileTypeProvider;

final class BuddyBossProfileTypeProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function provider(): BuddyBossProfileTypeProvider {
		return new BuddyBossProfileTypeProvider();
	}

	/**
	 * Return a provider whose is_available() returns the given value.
	 *
	 * Avoids monkey-patching PHP's function_exists() so we can verify the
	 * guard behaviour in get_options() / user_has_access() deterministically.
	 *
	 * @param bool $available
	 *
	 * @return BuddyBossProfileTypeProvider
	 */
	private function provider_with_availability( bool $available ): BuddyBossProfileTypeProvider {
		return new class( $available ) extends BuddyBossProfileTypeProvider {
			private bool $available;
			public function __construct( bool $available ) {
				$this->available = $available;
			}
			public function is_available(): bool {
				return $this->available;
			}
		};
	}

	private function type_object( string $singular_name ): object {
		$obj         = new \stdClass();
		$obj->labels = array( 'singular_name' => $singular_name );
		return $obj;
	}

	// -------------------------------------------------------------------------
	// Identity / metadata
	// -------------------------------------------------------------------------

	public function test_get_id_returns_bb_profile_type(): void {
		$this->assertSame( 'bb_profile_type', $this->provider()->get_id() );
	}

	public function test_get_label_returns_buddyboss_profile_type_string(): void {
		$this->assertSame( 'BuddyBoss Profile Type', $this->provider()->get_label() );
	}

	// -------------------------------------------------------------------------
	// is_available()
	// -------------------------------------------------------------------------

	public function test_is_available_returns_true_when_both_buddyboss_functions_exist(): void {
		Functions\when( 'bp_get_member_type' )->justReturn( false );
		Functions\when( 'bp_get_member_types' )->justReturn( array() );

		$this->assertTrue( $this->provider()->is_available() );
	}

	// -------------------------------------------------------------------------
	// get_options() — availability guard
	// -------------------------------------------------------------------------

	public function test_get_options_returns_empty_array_when_buddyboss_is_inactive(): void {
		// Filter must not be applied when BuddyBoss is missing — caller
		// shouldn't be able to inject options into a disabled provider.
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_options' )->never();

		$this->assertSame( array(), $this->provider_with_availability( false )->get_options() );
	}

	// -------------------------------------------------------------------------
	// get_options() — happy path & shape
	// -------------------------------------------------------------------------

	public function test_get_options_uses_singular_name_label_from_type_object(): void {
		Functions\when( 'bp_get_member_type' )->justReturn( false );
		Functions\when( 'bp_get_member_types' )->justReturn(
			array(
				'customer' => $this->type_object( 'Customer' ),
			)
		);
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_options' )->once()->andReturnFirstArg();

		$this->assertSame(
			array( array( 'id' => 'customer', 'label' => 'Customer' ) ),
			$this->provider()->get_options()
		);
	}

	public function test_get_options_falls_back_to_slug_when_singular_name_missing(): void {
		Functions\when( 'bp_get_member_type' )->justReturn( false );
		$broken_a         = new \stdClass();
		$broken_a->labels = array();
		$broken_b         = new \stdClass(); // no labels property at all
		Functions\when( 'bp_get_member_types' )->justReturn(
			array(
				'vendor'  => $broken_a,
				'partner' => $broken_b,
			)
		);
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_options' )->andReturnFirstArg();

		$options = $this->provider()->get_options();

		$ids    = array_column( $options, 'id' );
		$labels = array_column( $options, 'label' );

		// Both fall back to the slug; sort order is by label (case-insensitive)
		// → 'partner' < 'vendor'.
		$this->assertSame( array( 'partner', 'vendor' ), $ids );
		$this->assertSame( array( 'partner', 'vendor' ), $labels );
	}

	public function test_get_options_falls_back_to_slug_when_singular_name_is_empty_string(): void {
		Functions\when( 'bp_get_member_type' )->justReturn( false );
		$type         = new \stdClass();
		$type->labels = array( 'singular_name' => '' );
		Functions\when( 'bp_get_member_types' )->justReturn( array( 'vendor' => $type ) );
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_options' )->andReturnFirstArg();

		$this->assertSame(
			array( array( 'id' => 'vendor', 'label' => 'vendor' ) ),
			$this->provider()->get_options()
		);
	}

	public function test_get_options_sorts_alphabetically_case_insensitive_by_label(): void {
		Functions\when( 'bp_get_member_type' )->justReturn( false );
		Functions\when( 'bp_get_member_types' )->justReturn(
			array(
				'zeta'  => $this->type_object( 'zeta' ),
				'alpha' => $this->type_object( 'Alpha' ),
				'mu'    => $this->type_object( 'mu' ),
			)
		);
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_options' )->andReturnFirstArg();

		$labels = array_column( $this->provider()->get_options(), 'label' );

		$this->assertSame( array( 'Alpha', 'mu', 'zeta' ), $labels );
	}

	public function test_get_options_returns_empty_when_bp_get_member_types_returns_non_array(): void {
		Functions\when( 'bp_get_member_type' )->justReturn( false );
		Functions\when( 'bp_get_member_types' )->justReturn( null );
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_options' )->once()->andReturn( array() );

		$this->assertSame( array(), $this->provider()->get_options() );
	}

	public function test_get_options_filter_can_replace_the_entire_list(): void {
		Functions\when( 'bp_get_member_type' )->justReturn( false );
		Functions\when( 'bp_get_member_types' )->justReturn(
			array( 'customer' => $this->type_object( 'Customer' ) )
		);
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_options' )
			->once()
			->andReturn( array( array( 'id' => 'override', 'label' => 'Override' ) ) );

		$this->assertSame(
			array( array( 'id' => 'override', 'label' => 'Override' ) ),
			$this->provider()->get_options()
		);
	}

	public function test_get_options_casts_non_array_filter_return_back_to_array(): void {
		Functions\when( 'bp_get_member_type' )->justReturn( false );
		Functions\when( 'bp_get_member_types' )->justReturn( array() );
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_options' )->andReturn( null );

		$this->assertSame( array(), $this->provider()->get_options() );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — availability guard
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_buddyboss_is_inactive(): void {
		// bp_get_member_type must NOT be called when the plugin is missing.
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_has_access' )->never();

		$this->assertFalse(
			$this->provider_with_availability( false )->user_has_access( 7, array( 'customer' ) )
		);
	}

	// -------------------------------------------------------------------------
	// user_has_access() — empty options short-circuit
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_selected_options_empty(): void {
		Functions\when( 'bp_get_member_type' )->justReturn( false );
		Functions\when( 'bp_get_member_types' )->justReturn( array() );
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_has_access' )->never();

		$this->assertFalse( $this->provider()->user_has_access( 7, array() ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — no user types (BuddyBoss returns false)
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_user_has_no_profile_types(): void {
		Functions\when( 'bp_get_member_types' )->justReturn( array() );
		Functions\expect( 'bp_get_member_type' )->once()->with( 7, false )->andReturn( false );
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_has_access' )
			->once()
			->with( false, 7, array( 'customer' ) )
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 7, array( 'customer' ) ) );
	}

	public function test_user_has_access_returns_false_when_user_types_is_empty_array(): void {
		Functions\when( 'bp_get_member_types' )->justReturn( array() );
		Functions\expect( 'bp_get_member_type' )->once()->with( 7, false )->andReturn( array() );
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_has_access' )
			->once()
			->with( false, 7, array( 'customer' ) )
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 7, array( 'customer' ) ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — intersect match (true cases)
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_true_when_user_type_matches_single_selected(): void {
		Functions\when( 'bp_get_member_types' )->justReturn( array() );
		Functions\expect( 'bp_get_member_type' )->once()->with( 7, false )->andReturn( array( 'customer' ) );
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_has_access' )
			->once()
			->with( true, 7, array( 'customer' ) )
			->andReturn( true );

		$this->assertTrue( $this->provider()->user_has_access( 7, array( 'customer' ) ) );
	}

	public function test_user_has_access_returns_true_when_one_of_multiple_user_types_matches(): void {
		Functions\when( 'bp_get_member_types' )->justReturn( array() );
		Functions\expect( 'bp_get_member_type' )->once()->with( 7, false )->andReturn( array( 'subscriber', 'vendor' ) );
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_has_access' )
			->once()
			->with( true, 7, array( 'customer', 'vendor' ) )
			->andReturn( true );

		$this->assertTrue( $this->provider()->user_has_access( 7, array( 'customer', 'vendor' ) ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — no intersection (filter-driven cases)
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_no_user_type_matches_and_filter_keeps_false(): void {
		Functions\when( 'bp_get_member_types' )->justReturn( array() );
		Functions\expect( 'bp_get_member_type' )->once()->with( 7, false )->andReturn( array( 'subscriber' ) );
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_has_access' )
			->once()
			->with( false, 7, array( 'customer', 'vendor' ) )
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 7, array( 'customer', 'vendor' ) ) );
	}

	public function test_user_has_access_filter_can_override_false_to_true(): void {
		Functions\when( 'bp_get_member_types' )->justReturn( array() );
		Functions\expect( 'bp_get_member_type' )->once()->with( 7, false )->andReturn( false );
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_has_access' )
			->once()
			->with( false, 7, array( 'customer' ) )
			->andReturn( true );

		$this->assertTrue( $this->provider()->user_has_access( 7, array( 'customer' ) ) );
	}

	public function test_user_has_access_filter_truthy_value_is_cast_to_bool(): void {
		Functions\when( 'bp_get_member_types' )->justReturn( array() );
		Functions\expect( 'bp_get_member_type' )->once()->andReturn( false );
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_has_access' )
			->once()
			->andReturn( 1 );

		$this->assertTrue( $this->provider()->user_has_access( 1, array( 'customer' ) ) );
	}

	public function test_user_has_access_filter_falsy_value_is_cast_to_bool(): void {
		Functions\when( 'bp_get_member_types' )->justReturn( array() );
		Functions\expect( 'bp_get_member_type' )->once()->andReturn( array( 'customer' ) );
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_has_access' )
			->once()
			->andReturn( '' );

		$this->assertFalse( $this->provider()->user_has_access( 1, array( 'customer' ) ) );
	}

	public function test_user_has_access_uses_strict_intersect_comparison(): void {
		Functions\when( 'bp_get_member_types' )->justReturn( array() );
		Functions\expect( 'bp_get_member_type' )->once()->with( 7, false )->andReturn( array( 'Customer' ) );
		Filters\expectApplied( 'wpb_access_control_bb_profile_type_has_access' )
			->once()
			->andReturn( false );

		// Profile-type slugs are case-sensitive — 'Customer' should not match 'customer'.
		$this->assertFalse( $this->provider()->user_has_access( 7, array( 'customer' ) ) );
	}
}
