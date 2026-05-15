<?php
/**
 * Unit tests for Rule\Query high-level semantics.
 *
 * Tests get_rule / set_rule / clear_rule / purge_namespace logic by mocking
 * only the BerlinDB Query primitives (query, add_item, delete_item) so we
 * verify OUR aggregation/dispatch logic without needing a live database.
 *
 * Brain Monkey stubs WordPress functions.
 *
 * Transient stubs are NOT registered globally in setUp() because Brain Monkey
 * (via Mockery) uses FIFO matching: a when() stub added in setUp would
 * consume all calls before any expect() added in a test body gets a chance to
 * match. Each test therefore registers only the stubs it needs.
 */

namespace WPBoilerplate\AccessControl\Tests\Unit\Database\Rule;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPBoilerplate\AccessControl\Database\Rule\RuleQuery;
use WPBoilerplate\AccessControl\Database\Rule\RuleRow;

final class QueryRuleSemanticsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_key' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a partial mock of RuleQuery with query/add_item/delete_item stubbed.
	 *
	 * @param string[] $methods Additional methods to mock.
	 *
	 * @return RuleQuery&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_query( array $methods = array() ): RuleQuery {
		return $this->getMockBuilder( RuleQuery::class )
			->disableOriginalConstructor()
			->onlyMethods( array_merge( array( 'query', 'add_item', 'delete_item' ), $methods ) )
			->getMock();
	}

	private function make_row( string $ac_key, string $ac_value ): RuleRow {
		$row                       = new RuleRow();
		$row->id                   = 1;
		$row->namespace            = 'test/v1';
		$row->key                  = 'thing';
		$row->access_control_key   = $ac_key;
		$row->access_control_value = $ac_value;
		return $row;
	}

	/** Stub all three transient functions as no-ops (for tests that don\'t assert on them). */
	private function stub_transients(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	// -------------------------------------------------------------------------
	// get_rule
	// -------------------------------------------------------------------------

	public function test_get_rule_returns_empty_shape_when_no_rows(): void {
		$this->stub_transients();

		$q = $this->make_query();
		$q->expects( $this->once() )
		  ->method( 'query' )
		  ->willReturn( array() );

		$result = $q->get_rule( 'ns', 'key' );

		$this->assertSame( array( 'key' => '', 'value' => array() ), $result );
	}

	public function test_get_rule_assembles_multiple_option_rows(): void {
		$this->stub_transients();

		$q = $this->make_query();
		$q->expects( $this->once() )
		  ->method( 'query' )
		  ->willReturn( array(
			  $this->make_row( 'wp_role', 'editor' ),
			  $this->make_row( 'wp_role', 'author' ),
		  ) );

		$result = $q->get_rule( 'ns', 'key' );

		$this->assertSame( 'wp_role', $result['key'] );
		$this->assertSame( array( 'editor', 'author' ), $result['value'] );
	}

	public function test_get_rule_everyone_returns_empty_value_array(): void {
		$this->stub_transients();

		$q = $this->make_query();
		$q->expects( $this->once() )
		  ->method( 'query' )
		  ->willReturn( array( $this->make_row( 'everyone', '' ) ) );

		$result = $q->get_rule( 'ns', 'key' );

		$this->assertSame( 'everyone', $result['key'] );
		$this->assertSame( array(), $result['value'] );
	}

	// -------------------------------------------------------------------------
	// set_rule — cleared state
	// -------------------------------------------------------------------------

	public function test_set_rule_empty_key_purges_and_writes_nothing(): void {
		$this->stub_transients();

		$q = $this->make_query();
		// purge_resource calls query() to get IDs, then delete_item() for each.
		$q->expects( $this->once() )
		  ->method( 'query' )
		  ->willReturn( array() ); // nothing to delete

		$q->expects( $this->never() )
		  ->method( 'add_item' );

		$result = $q->set_rule( 'ns', 'key', '', array() );

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// set_rule — everyone
	// -------------------------------------------------------------------------

	public function test_set_rule_everyone_writes_one_sentinel_row(): void {
		$this->stub_transients();

		$q = $this->make_query();
		$q->method( 'query' )->willReturn( array() ); // purge finds nothing

		$q->expects( $this->once() )
		  ->method( 'add_item' )
		  ->with( $this->callback( function ( array $data ) {
			  return 'everyone' === $data['access_control_key']
			      && '' === $data['access_control_value'];
		  } ) )
		  ->willReturn( 1 );

		$result = $q->set_rule( 'ns', 'key', 'everyone', array() );

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// set_rule — multi-option rule
	// -------------------------------------------------------------------------

	public function test_set_rule_wp_role_writes_one_row_per_option(): void {
		$this->stub_transients();

		$q = $this->make_query();
		$q->method( 'query' )->willReturn( array() ); // purge finds nothing

		$q->expects( $this->exactly( 2 ) )
		  ->method( 'add_item' )
		  ->withConsecutive(
			  array( $this->callback( fn( array $d ) => $d['access_control_value'] === 'editor' ) ),
			  array( $this->callback( fn( array $d ) => $d['access_control_value'] === 'author' ) )
		  )
		  ->willReturn( 1 );

		$result = $q->set_rule( 'ns', 'key', 'wp_role', array( 'editor', 'author' ) );

		$this->assertTrue( $result );
	}

	public function test_set_rule_purges_existing_rows_before_writing(): void {
		$this->stub_transients();

		$q = $this->make_query();

		$q->expects( $this->once() )
		  ->method( 'query' )
		  ->willReturn( array( 99 ) ); // purge_resource finds one stale row

		$q->expects( $this->once() )
		  ->method( 'delete_item' )
		  ->with( 99 )
		  ->willReturn( true );

		$q->expects( $this->once() )
		  ->method( 'add_item' )
		  ->willReturn( 1 );

		$q->set_rule( 'ns', 'key', 'wp_role', array( 'editor' ) );
	}

	// -------------------------------------------------------------------------
	// clear_rule
	// -------------------------------------------------------------------------

	public function test_clear_rule_deletes_all_rows_for_resource(): void {
		$this->stub_transients();

		$q = $this->make_query();
		$q->expects( $this->once() )
		  ->method( 'query' )
		  ->willReturn( array( 5, 6 ) );

		$q->expects( $this->exactly( 2 ) )
		  ->method( 'delete_item' )
		  ->withConsecutive( array( 5 ), array( 6 ) );

		$result = $q->clear_rule( 'ns', 'key' );

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// purge_namespace
	// -------------------------------------------------------------------------

	public function test_purge_namespace_deletes_only_namespace_rows(): void {
		$this->stub_transients();

		$q = $this->make_query();

		$row1            = new RuleRow();
		$row1->id        = 10;
		$row1->namespace = 'testns';
		$row1->key       = 'res/a';

		$row2            = new RuleRow();
		$row2->id        = 11;
		$row2->namespace = 'testns';
		$row2->key       = 'res/b';

		$row3            = new RuleRow();
		$row3->id        = 12;
		$row3->namespace = 'testns';
		$row3->key       = 'res/a'; // duplicate key — transient deleted once

		$q->expects( $this->once() )
		  ->method( 'query' )
		  ->with( $this->callback( function ( array $args ) {
			  return 'testns' === $args['namespace'] && 0 === $args['number'];
		  } ) )
		  ->willReturn( array( $row1, $row2, $row3 ) );

		$q->expects( $this->exactly( 3 ) )
		  ->method( 'delete_item' )
		  ->willReturn( true );

		$count = $q->purge_namespace( 'testns' );

		$this->assertSame( 3, $count );
	}

	public function test_purge_namespace_returns_zero_when_no_rows(): void {
		$this->stub_transients();

		$q = $this->make_query();
		$q->method( 'query' )->willReturn( array() );
		$q->expects( $this->never() )->method( 'delete_item' );

		$this->assertSame( 0, $q->purge_namespace( 'empty-ns' ) );
	}

	// -------------------------------------------------------------------------
	// Transient caching
	// -------------------------------------------------------------------------

	public function test_get_rule_returns_cached_value_without_querying_db(): void {
		$cached = array( 'key' => 'wp_role', 'value' => array( 'editor' ) );

		Functions\expect( 'get_transient' )->once()->andReturn( $cached );
		Functions\when( 'set_transient' )->justReturn( true );

		$q = $this->make_query();
		$q->expects( $this->never() )->method( 'query' );

		$result = $q->get_rule( 'ns', 'key' );

		$this->assertSame( $cached, $result );
	}

	public function test_get_rule_populates_transient_on_cache_miss(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->once()->with(
			$this->isType( 'string' ),
			array( 'key' => 'wp_role', 'value' => array( 'editor' ) ),
			RuleQuery::TRANSIENT_TTL
		);

		$q = $this->make_query();
		$q->method( 'query' )->willReturn( array( $this->make_row( 'wp_role', 'editor' ) ) );

		$result = $q->get_rule( 'ns', 'key' );

		$this->assertSame( array( 'key' => 'wp_role', 'value' => array( 'editor' ) ), $result );
	}

	public function test_set_rule_invalidates_transient(): void {
		Functions\expect( 'delete_transient' )->atLeast()->once();

		$q = $this->make_query();
		$q->method( 'query' )->willReturn( array() );
		$q->method( 'add_item' )->willReturn( 1 );

		$result = $q->set_rule( 'ns', 'key', 'everyone', array() );

		$this->assertTrue( $result );
	}

	public function test_clear_rule_invalidates_transient(): void {
		Functions\expect( 'delete_transient' )->once();

		$q = $this->make_query();
		$q->method( 'query' )->willReturn( array() );

		$result = $q->clear_rule( 'ns', 'key' );

		$this->assertTrue( $result );
	}

	public function test_purge_namespace_invalidates_transient_once_per_unique_key(): void {
		$row1            = new RuleRow();
		$row1->id        = 1;
		$row1->namespace = 'ns';
		$row1->key       = 'same-key';

		$row2            = new RuleRow();
		$row2->id        = 2;
		$row2->namespace = 'ns';
		$row2->key       = 'same-key'; // duplicate key — transient deleted only once

		Functions\expect( 'delete_transient' )->once();

		$q = $this->make_query();
		$q->method( 'query' )->willReturn( array( $row1, $row2 ) );
		$q->method( 'delete_item' )->willReturn( true );

		$count = $q->purge_namespace( 'ns' );

		$this->assertSame( 2, $count );
	}
}
