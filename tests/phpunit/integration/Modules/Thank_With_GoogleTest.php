<?php
/**
 * Thank_With_GoogleTest
 *
 * @package   Google\Site_Kit\Tests\Modules
 * @copyright 2021 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Tests\Modules;

use Google\Site_Kit\Context;
use Google\Site_Kit\Core\Storage\Options;
use Google\Site_Kit\Modules\Thank_With_Google;
use Google\Site_Kit\Modules\Thank_With_Google\Settings;
use Google\Site_Kit\Tests\TestCase;

/**
 * @group Modules
 */
class Thank_With_GoogleTest extends TestCase {

	/**
	 * Context instance.
	 *
	 * @var Context
	 */
	private $context;

	/**
	 * Thank_With_Google instance.
	 *
	 * @var Thank_With_Google
	 */
	private $thank_with_google;

	public function set_up() {
		parent::set_up();

		$this->context           = new Context( GOOGLESITEKIT_PLUGIN_MAIN_FILE );
		$this->thank_with_google = new Thank_With_Google( $this->context );
	}

	public function test_is_connected() {
		$options           = new Options( $this->context );
		$thank_with_google = new Thank_With_Google( $this->context, $options );

		$options->set(
			Settings::OPTION,
			array(
				'publicationID'   => '12345',
				'colorTheme'      => 'light',
				'buttonPlacement' => 'bottom',
				'buttonPostTypes' => array( 'post' ),
			)
		);

		$this->assertTrue( $thank_with_google->is_connected() );
	}

	public function test_on_deactivation() {
		$thank_with_google = new Thank_With_Google( new Context( GOOGLESITEKIT_PLUGIN_MAIN_FILE ) );
		$options           = new Options( new Context( GOOGLESITEKIT_PLUGIN_MAIN_FILE ) );
		$options->set( Settings::OPTION, 'test-value' );

		$thank_with_google->on_deactivation();

		$this->assertOptionNotExists( Settings::OPTION );
	}
}
