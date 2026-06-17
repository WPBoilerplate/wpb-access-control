<?php
/**
 * Unit tests for MemberPressMembershipProvider — gates access by MemberPress
 * membership.
 *
 * Covers identity (get_id, get_label), availability gating (is_available
 * happy path, get_options + user_has_access short-circuits when MemberPress
 * is inactive), the get_options pipeline (post→option mapping, title
 * fallback, filter override), and user_has_access (empty options, no active
 * subscriptions, intersect match, multi-membership match, filter override,
 * string/int ID coercion).
 *
 * MemberPress dependencies are simulated at the global namespace level:
 *   - `MEPR_VERSION` is defined once (PHP constants persist for the run).
 *   - A minimal `MeprUser` stub class is declared once with a per-test
 *     return value driven by a public static property.
 *
 * For tests that need `is_available()` to return false (verifying the guard
 * short-circuits), a partial-mock subclass is used to avoid trying to
 * un-define the constant/class.
 */

namespace WPBoilerplate\AccessControl\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPBoilerplate\AccessControl\MemberPressMembershipProvider;

// `MEPR_VERSION` constant and global `\MeprUser` stub class are declared in
// tests/bootstrap.php so they live in the global namespace and are loaded
// once per PHP process — PHP constants and class declarations both persist.

final class MemberPressMembershipProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		\MeprUser::$next_subscriptions = array();
	}

	protected function tearDown(): void {
		\MeprUser::$next_subscriptions = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function provider(): MemberPressMembershipProvider {
		return new MemberPressMembershipProvider();
	}

	/**
	 * Return a provider whose is_available() is forced to the given value.
	 *
	 * Avoids un-defining MEPR_VERSION / MeprUser (which PHP can't undo) when
	 * testing the unavailability short-circuits.
	 */
	private function provider_with_availability( bool $available ): MemberPressMembershipProvider {
		return new class( $available ) extends MemberPressMembershipProvider {
			private bool $available;
			public function __construct( bool $available ) {
				$this->available = $available;
			}
			public function is_available(): bool {
				return $this->available;
			}
		};
	}

	private function post( int $id, string $title ): object {
		$post             = new \stdClass();
		$post->ID         = $id;
		$post->post_title = $title;
		return $post;
	}

	// -------------------------------------------------------------------------
	// Identity / metadata
	// -------------------------------------------------------------------------

	public function test_get_id_returns_mepr_membership(): void {
		$this->assertSame( 'mepr_membership', $this->provider()->get_id() );
	}

	public function test_get_label_returns_memberpress_membership_string(): void {
		$this->assertSame( 'MemberPress Membership', $this->provider()->get_label() );
	}

	// -------------------------------------------------------------------------
	// is_available()
	// -------------------------------------------------------------------------

	public function test_is_available_returns_true_when_constant_and_class_present(): void {
		// MEPR_VERSION and MeprUser are defined at the top of this file.
		$this->assertTrue( $this->provider()->is_available() );
	}

	// -------------------------------------------------------------------------
	// get_options() — availability guard
	// -------------------------------------------------------------------------

	public function test_get_options_returns_empty_array_when_memberpress_is_inactive(): void {
		// get_posts must NOT be called when the plugin is missing.
		Functions\expect( 'get_posts' )->never();
		Filters\expectApplied( 'wpb_access_control_mepr_membership_options' )->never();

		$this->assertSame( array(), $this->provider_with_availability( false )->get_options() );
	}

	// -------------------------------------------------------------------------
	// get_options() — happy path & shape
	// -------------------------------------------------------------------------

	public function test_get_options_maps_each_membership_post_to_id_label_pair(): void {
		Functions\expect( 'get_posts' )
			->once()
			->with(
				\Mockery::on(
					static function ( $args ) {
						return is_array( $args )
							&& 'memberpressproduct' === ( $args['post_type'] ?? null )
							&& 'publish' === ( $args['post_status'] ?? null );
					}
				)
			)
			->andReturn(
				array(
					$this->post( 42, 'Gold' ),
					$this->post( 100, 'Silver' ),
				)
			);
		Filters\expectApplied( 'wpb_access_control_mepr_membership_options' )->once()->andReturnFirstArg();

		$this->assertSame(
			array(
				array( 'id' => '42',  'label' => 'Gold' ),
				array( 'id' => '100', 'label' => 'Silver' ),
			),
			$this->provider()->get_options()
		);
	}

	public function test_get_options_falls_back_to_synthetic_title_when_post_title_empty(): void {
		Functions\expect( 'get_posts' )->once()->andReturn(
			array( $this->post( 7, '' ) )
		);
		Filters\expectApplied( 'wpb_access_control_mepr_membership_options' )->andReturnFirstArg();

		$options = $this->provider()->get_options();

		$this->assertSame( '7', $options[0]['id'] );
		$this->assertSame( 'Membership #7', $options[0]['label'] );
	}

	public function test_get_options_skips_posts_missing_id_field(): void {
		$broken = new \stdClass(); // no ID property
		Functions\expect( 'get_posts' )->once()->andReturn(
			array( $broken, $this->post( 9, 'Bronze' ) )
		);
		Filters\expectApplied( 'wpb_access_control_mepr_membership_options' )->andReturnFirstArg();

		$this->assertSame(
			array( array( 'id' => '9', 'label' => 'Bronze' ) ),
			$this->provider()->get_options()
		);
	}

	public function test_get_options_returns_empty_when_get_posts_returns_non_array(): void {
		Functions\expect( 'get_posts' )->once()->andReturn( null );
		Filters\expectApplied( 'wpb_access_control_mepr_membership_options' )->once()->andReturn( array() );

		$this->assertSame( array(), $this->provider()->get_options() );
	}

	public function test_get_options_filter_can_replace_the_entire_list(): void {
		Functions\expect( 'get_posts' )->once()->andReturn(
			array( $this->post( 1, 'Gold' ) )
		);
		Filters\expectApplied( 'wpb_access_control_mepr_membership_options' )
			->once()
			->andReturn( array( array( 'id' => 'override', 'label' => 'Override' ) ) );

		$this->assertSame(
			array( array( 'id' => 'override', 'label' => 'Override' ) ),
			$this->provider()->get_options()
		);
	}

	public function test_get_options_casts_non_array_filter_return_back_to_array(): void {
		Functions\expect( 'get_posts' )->once()->andReturn( array() );
		Filters\expectApplied( 'wpb_access_control_mepr_membership_options' )->andReturn( null );

		$this->assertSame( array(), $this->provider()->get_options() );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — availability guard
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_memberpress_is_inactive(): void {
		// MeprUser must NOT be touched when the plugin is missing.
		Filters\expectApplied( 'wpb_access_control_mepr_membership_has_access' )->never();
		\MeprUser::$next_subscriptions = array( 999 ); // sentinel: would match if called

		$this->assertFalse(
			$this->provider_with_availability( false )->user_has_access( 7, array( '999' ) )
		);
	}

	// -------------------------------------------------------------------------
	// user_has_access() — empty options short-circuit
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_selected_options_empty(): void {
		\MeprUser::$next_subscriptions = array( 42 );
		Filters\expectApplied( 'wpb_access_control_mepr_membership_has_access' )->never();

		$this->assertFalse( $this->provider()->user_has_access( 7, array() ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — no active subscriptions
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_user_has_no_active_subscriptions(): void {
		\MeprUser::$next_subscriptions = array();
		Filters\expectApplied( 'wpb_access_control_mepr_membership_has_access' )
			->once()
			->with( false, 7, array( '42' ) )
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 7, array( '42' ) ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — intersect match (true cases)
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_true_when_active_subscription_matches_single_selected(): void {
		\MeprUser::$next_subscriptions = array( 42 );
		Filters\expectApplied( 'wpb_access_control_mepr_membership_has_access' )
			->once()
			->with( true, 7, array( '42' ) )
			->andReturn( true );

		$this->assertTrue( $this->provider()->user_has_access( 7, array( '42' ) ) );
	}

	public function test_user_has_access_returns_true_when_one_of_multiple_user_memberships_matches(): void {
		\MeprUser::$next_subscriptions = array( 13, 42 );
		Filters\expectApplied( 'wpb_access_control_mepr_membership_has_access' )
			->once()
			->with( true, 7, array( '42', '100' ) )
			->andReturn( true );

		$this->assertTrue( $this->provider()->user_has_access( 7, array( '42', '100' ) ) );
	}

	public function test_user_has_access_coerces_int_subscription_ids_to_strings_for_intersect(): void {
		// MeprUser returns ints; stored options are strings — provider must
		// coerce or the strict intersect will miss.
		\MeprUser::$next_subscriptions = array( 42 );
		Filters\expectApplied( 'wpb_access_control_mepr_membership_has_access' )
			->once()
			->with( true, 7, array( '42' ) )
			->andReturn( true );

		$this->assertTrue( $this->provider()->user_has_access( 7, array( '42' ) ) );
	}

	// -------------------------------------------------------------------------
	// user_has_access() — no intersection (filter-driven cases)
	// -------------------------------------------------------------------------

	public function test_user_has_access_returns_false_when_no_membership_matches_and_filter_keeps_false(): void {
		\MeprUser::$next_subscriptions = array( 13 );
		Filters\expectApplied( 'wpb_access_control_mepr_membership_has_access' )
			->once()
			->with( false, 7, array( '42', '100' ) )
			->andReturn( false );

		$this->assertFalse( $this->provider()->user_has_access( 7, array( '42', '100' ) ) );
	}

	public function test_user_has_access_filter_can_override_false_to_true(): void {
		\MeprUser::$next_subscriptions = array();
		Filters\expectApplied( 'wpb_access_control_mepr_membership_has_access' )
			->once()
			->with( false, 7, array( '42' ) )
			->andReturn( true );

		$this->assertTrue( $this->provider()->user_has_access( 7, array( '42' ) ) );
	}

	public function test_user_has_access_filter_truthy_value_is_cast_to_bool(): void {
		\MeprUser::$next_subscriptions = array();
		Filters\expectApplied( 'wpb_access_control_mepr_membership_has_access' )
			->once()
			->andReturn( 1 );

		$this->assertTrue( $this->provider()->user_has_access( 1, array( '42' ) ) );
	}

	public function test_user_has_access_filter_falsy_value_is_cast_to_bool(): void {
		\MeprUser::$next_subscriptions = array( 42 );
		Filters\expectApplied( 'wpb_access_control_mepr_membership_has_access' )
			->once()
			->andReturn( '' );

		$this->assertFalse( $this->provider()->user_has_access( 1, array( '42' ) ) );
	}
}
