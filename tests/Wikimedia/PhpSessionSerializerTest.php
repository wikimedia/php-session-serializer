<?php
/**
 * php-session-serializer
 *
 * Copyright (C) 2015 Brad Jorsch <bjorsch@wikimedia.org>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @author Brad Jorsch <bjorsch@wikimedia.org>
 */

namespace Wikimedia;

use InvalidArgumentException;
use Psr\Log\LogLevel;
use Wikimedia\PhpSessionSerializer\TestLogger;

// Wikimedia\PhpSessionSerializer relies on the auto-importing of ini_set and
// ini_get from the global namespace. In this unit test, we override these with
// a namespace-local version to allow for testing failure modes.
$wgMockIniInstance = null;

function ini_set( $var, $value ) {
	global $wgMockIniInstance;
	return $wgMockIniInstance
		? $wgMockIniInstance->mockIniSet( $var, $value )
		: \ini_set( $var, $value );
}

function ini_get( $var ) {
	global $wgMockIniInstance;
	return $wgMockIniInstance
		? $wgMockIniInstance->mockIniGet( $var )
		: \ini_get( $var );
}

/**
 * @covers Wikimedia\PhpSessionSerializer
 */
class PhpSessionSerializerTest extends \PHPUnit\Framework\TestCase {

	/** @var string|null */
	protected $oldFormat;

	/** @var string[]|null */
	protected $mockIniAllowed;
	/** @var string|null */
	protected $mockIniValue;

	/** @var array */
	protected static $standardArray;
	/** @var string */
	protected static $longKey;
	/** @var callable */
	protected static $closure;

	public function mockIniGet( $var ) {
		if ( $var !== 'session.serialize_handler' || !is_array( $this->mockIniAllowed ) ) {
			return \ini_get( $var );
		}

		return $this->mockIniValue;
	}

	public function mockIniSet( $var, $value ) {
		if ( $var !== 'session.serialize_handler' || !is_array( $this->mockIniAllowed ) ) {
			return \ini_set( $var, $value );
		}

		if ( in_array( $value, $this->mockIniAllowed, true ) ) {
			$old = $this->mockIniValue;
			$this->mockIniValue = $value;
			return $old;
		} else {
			// trigger_error to test that warnings are properly suppressed
			trigger_error( "mockIniSet disallows setting to '$value'", E_USER_WARNING );
			return false;
		}
	}

	protected static function initTestData() {
		self::$longKey = str_pad( 'long key ', 128, '-' );
		self::$standardArray = [
			'true' => true,
			'false' => false,
			'int' => 42,
			'zero' => 0,
			'double' => 12.75,
			'inf' => INF,
			'-inf' => -INF,
			'string' => 'string',
			'empty string' => '',
			'array' => [ 0, 1, 100 => 100, 3 => 3, 2 => 2, 'foo' => 'bar' ],
			'empty array' => [],
			'object' => (object)[ 'foo' => 'foo' ],
			'empty object' => new \stdClass,
			'null' => null,
			'' => 'empty key',
			42 => 42,
			self::$longKey => 'long key',
		];

		self::$closure = static function () {
		};
	}

	protected function setUp(): void {
		global $wgMockIniInstance;
		parent::setUp();
		$this->oldFormat = \ini_get( 'session.serialize_handler' );
		PhpSessionSerializer::setLogger( new TestLogger() );
		$wgMockIniInstance = $this;
		$this->mockIniValue = $this->oldFormat;
	}

	protected function tearDown(): void {
		global $wgMockIniInstance;
		$wgMockIniInstance = null;
		$this->assertSame( $this->oldFormat, \ini_get( 'session.serialize_handler' ),
		   'Assert that the test didn\'t change the ini setting' );
		parent::tearDown();
	}

	public function testSetSerializeHandler() {
		try {
			$ret = PhpSessionSerializer::setSerializeHandler();
			$this->assertSame( $ret, \ini_get( 'session.serialize_handler' ) );
		} finally {
			\ini_set( 'session.serialize_handler', $this->oldFormat );
		}
	}

	public function testSetSerializeHandlerMocked() {
		// Test setting php_serialize
		$this->mockIniAllowed = [ 'php_serialize', 'php', 'php_binary' ];
		$this->mockIniValue = 'php_binary';
		$ret = PhpSessionSerializer::setSerializeHandler();
		$this->assertSame( 'php_serialize', $this->mockIniValue );
		$this->assertSame( $ret, $this->mockIniValue );

		// Test defaulting to current if it's supported
		$this->mockIniAllowed = [ 'php', 'php_binary' ];
		$this->mockIniValue = 'php_binary';
		$ret = PhpSessionSerializer::setSerializeHandler();
		$this->assertSame( 'php_binary', $this->mockIniValue );
		$this->assertSame( $ret, $this->mockIniValue );

		// Test choosing a supported format
		$this->mockIniAllowed = [ 'php', 'php_binary' ];
		$this->mockIniValue = 'bogus';
		$ret = PhpSessionSerializer::setSerializeHandler();
		$this->assertSame( 'php', $this->mockIniValue );
		$this->assertSame( $ret, $this->mockIniValue );

		// Test failure
		$this->mockIniAllowed = [ 'nothing', 'useful' ];
		$this->mockIniValue = 'bogus';
		try {
			PhpSessionSerializer::setSerializeHandler();
			$this->fail( 'Expected exception not thrown' );
		} catch ( \DomainException $ex ) {
			$this->assertSame(
				'Failed to set serialize handler to a supported format.' .
					' Supported formats are: php_serialize, php, php_binary.',
				$ex->getMessage()
			);
		}
	}

	public static function provideHandlers() {
		return [
			[ 'php', 'test|b:1;' ],
			[ 'php_binary', "\x04testb:1;" ],
			[ 'php_serialize', 'a:1:{s:4:"test";b:1;}' ],
			[ 'bogus', new \DomainException( 'Unsupported format "bogus"' ) ],
			[
				false,
				new \UnexpectedValueException( 'Could not fetch the value of session.serialize_handler' )
			],
		];
	}

	/**
	 * @dataProvider provideHandlers
	 */
	public function testEncode( $format, $encoded ) {
		$this->mockIniAllowed = [ 'php_serialize', 'php', 'php_binary' ];
		$this->mockIniValue = $format;

		$data = [ 'test' => true ];
		if ( $encoded instanceof \Exception ) {
			try {
				PhpSessionSerializer::encode( $data );
				$this->fail( 'Expected exception not thrown' );
			} catch ( \Exception $ex ) {
				$this->assertInstanceOf( get_class( $encoded ), $ex );
				$this->assertSame( $encoded->getMessage(), $ex->getMessage() );
			}
		} else {
			$this->assertSame( $encoded, PhpSessionSerializer::encode( $data ) );
		}
	}

	/**
	 * @dataProvider provideHandlers
	 */
	public function testDecode( $format, $encoded ) {
		$this->mockIniAllowed = [ 'php_serialize', 'php', 'php_binary' ];
		$this->mockIniValue = $format;

		$data = [ 'test' => true ];
		if ( $encoded instanceof \Exception ) {
			try {
				PhpSessionSerializer::decode( '' );
				$this->fail( 'Expected exception not thrown' );
			} catch ( \Exception $ex ) {
				$this->assertInstanceOf( get_class( $encoded ), $ex );
				$this->assertSame( $encoded->getMessage(), $ex->getMessage() );
			}
		} else {
			$this->assertSame( $data, PhpSessionSerializer::decode( $encoded ) );
		}
	}

	public static function provideEncodePhp() {
		self::initTestData();
		return [
			'std' => [
				self::$standardArray,
				'true|b:1;false|b:0;int|i:42;zero|i:0;double|d:12.75;inf|d:INF;-inf|d:-INF;string|s:' .
					'6:"string";empty string|s:0:"";array|a:6:{i:0;i:0;i:1;i:1;i:100;i:100;i:3;i:3;i:2;i' .
					':2;s:3:"foo";s:3:"bar";}empty array|a:0:{}object|O:8:"stdClass":1:{s:3:"foo";s:3:"f' .
					'oo";}empty object|O:8:"stdClass":0:{}null|N;|s:9:"empty key";long key -------------' .
					'-----------------------------------------------------------------------------------' .
					'-----------------------|s:8:"long key";',
				[
					[ LogLevel::WARNING, 'Ignoring unsupported integer key "42"' ],
				],
			],
			[
				[ 'pipe|key' => 'piped' ],
				null,
				[
					[ LogLevel::ERROR, 'Serialization failed: Key with unsupported characters "pipe|key"' ],
				],
			],
			[
				[ 'bang!key' => 'banged' ],
				null,
				[
					[ LogLevel::ERROR, 'Serialization failed: Key with unsupported characters "bang!key"' ],
				],
			],
			[
				[ 'nan' => NAN ],
				'nan|d:NAN;',
				[],
			],
			[
				[ 'function' => self::$closure ],
				null,
				[
					[ LogLevel::ERROR, "Value serialization failed: Serialization of 'Closure' is not allowed" ],
				],
			],
		];
	}

	public static function provideDecodePhp() {
		$ret = array_filter( self::provideEncodePhp(), static function ( $x ) {
			return $x[1] !== null;
		} );
		unset( $ret['std'][0][42] );
		$ret['std'][2] = [];

		$ret[] = [
			null,
			'test|i:042;',
			[
				[
					LogLevel::ERROR, 'Value unserialization failed: read value does not match original string'
				]
			],
		];
		$ret[] = [
			null,
			'test|i:42',
			[
				[
					LogLevel::ERROR, 'Value unserialization failed: [unserialize error]'
				]
			],
		];
		$ret[] = [
			null,
			'test|i:42;X|',
			[
				[ LogLevel::ERROR, 'Unserialize failed: unexpected end of string' ]
			],
		];
		$ret[] = [
			[ 'test' => 42, 'test2' => 43 ],
			'test|i:42;test2|i:43;X!',
			[],
		];
		$ret[] = [
			[ 'test' => 42, 'X!test2' => 43 ],
			'test|i:42;X!test2|i:43;',
			[
				[ LogLevel::WARNING, 'Decoding found a key with unsupported characters: "X!test2"' ]
			],
		];
		$ret[] = [
			[],
			'test!',
			[],
		];
		$ret[] = [
			[ 'test' => 42 ],
			'test|i:42;test2',
			[
				[ LogLevel::WARNING, 'Ignoring garbage at end of string' ]
			],
		];

		return $ret;
	}

	/**
	 * @dataProvider provideEncodePhp
	 */
	public function testEncodePhp( $data, $encoded, $log ) {
		$logger = new TestLogger( true );
		PhpSessionSerializer::setLogger( $logger );
		$this->assertSame( $encoded, PhpSessionSerializer::encodePhp( $data ) );
		$this->assertSame( $log, $logger->getBuffer() );
	}

	/**
	 * @dataProvider provideDecodePhp
	 */
	public function testDecodePhp( $data, $encoded, $log ) {
		$logger = new TestLogger( true );
		PhpSessionSerializer::setLogger( $logger );
		if ( isset( $data['nan'] ) ) {
			$ret = PhpSessionSerializer::decodePhp( $encoded );
			$this->assertTrue( is_nan( $ret['nan'] ) );
		} else {
			$this->assertEquals( $data, PhpSessionSerializer::decodePhp( $encoded ) );
		}
		$this->assertSame( $log, $logger->getBuffer() );
	}

	public static function provideEncodePhpBinary() {
		self::initTestData();
		return [
			'std' => [
				self::$standardArray,
				"\x04trueb:1;\x05falseb:0;\x03inti:42;\x04zeroi:0;\x06doubled:12.75;\x03infd:INF;\x04" .
					"-infd:-INF;\x06strings:6:\"string\";\x0cempty strings:0:\"\";\x05arraya:6:{i:0;i:0;i:1;" .
					"i:1;i:100;i:100;i:3;i:3;i:2;i:2;s:3:\"foo\";s:3:\"bar\";}\x0bempty arraya:0:{}\x06object" .
					"O:8:\"stdClass\":1:{s:3:\"foo\";s:3:\"foo\";}\x0cempty objectO:8:\"stdClass\":0:{}\x04" .
					"nullN;\0s:9:\"empty key\";",
				[
					[ LogLevel::WARNING, 'Ignoring unsupported integer key "42"' ],
					[ LogLevel::WARNING, 'Ignoring overlong key "' . self::$longKey . '"' ],
				],
			],
			[
				[ 'pipe|key' => 'piped' ],
				"\x08pipe|keys:5:\"piped\";",
				[],
			],
			[
				[ 'bang!key' => 'banged' ],
				"\x08bang!keys:6:\"banged\";",
				[],
			],
			[
				[ 'nan' => NAN ],
				"\x03nand:NAN;",
				[],
			],
			[
				[ 'function' => self::$closure ],
				null,
				[
					[ LogLevel::ERROR, "Value serialization failed: Serialization of 'Closure' is not allowed" ],
				],
			],
		];
	}

	public static function provideDecodePhpBinary() {
		$ret = array_filter( self::provideEncodePhpBinary(), static function ( $x ) {
			return $x[1] !== null;
		} );
		unset( $ret['std'][0][42] );
		unset( $ret['std'][0][self::$longKey] );
		$ret['std'][2] = [];

		$ret[] = [
			null,
			"\x04testi:042;",
			[
				[
					LogLevel::ERROR, 'Value unserialization failed: read value does not match original string'
				]
			],
		];
		$ret[] = [
			null,
			"\x04testi:42",
			[
				[
					LogLevel::ERROR, 'Value unserialization failed: [unserialize error]'
				]
			],
		];
		$ret[] = [
			null,
			"\x50test",
			[
				[
					LogLevel::ERROR, 'Unserialize failed: unexpected end of string'
				]
			],
		];
		$ret[] = [
			null,
			"\x04test",
			[
				[
					LogLevel::ERROR, 'Unserialize failed: unexpected end of string'
				]
			],
		];
		$ret[] = [
			[ 'test' => 42, 'test2' => 43 ],
			"\x04testi:42;\x05test2i:43;\x81X",
			[],
		];
		$ret[] = [
			[],
			"\x84test",
			[],
		];

		return $ret;
	}

	/**
	 * @dataProvider provideEncodePhpBinary
	 */
	public function testEncodePhpBinary( $data, $encoded, $log ) {
		$logger = new TestLogger( true );
		PhpSessionSerializer::setLogger( $logger );
		$this->assertSame( $encoded, PhpSessionSerializer::encodePhpBinary( $data ) );
		$this->assertSame( $log, $logger->getBuffer() );
	}

	/**
	 * @dataProvider provideDecodePhpBinary
	 */
	public function testDecodePhpBinary( $data, $encoded, $log ) {
		$logger = new TestLogger( true );
		PhpSessionSerializer::setLogger( $logger );
		if ( isset( $data['nan'] ) ) {
			$ret = PhpSessionSerializer::decodePhpBinary( $encoded );
			$this->assertTrue( is_nan( $ret['nan'] ) );
		} else {
			$this->assertEquals( $data, PhpSessionSerializer::decodePhpBinary( $encoded ) );
		}
		$this->assertSame( $log, $logger->getBuffer() );
	}

	public static function provideEncodePhpSerialize() {
		self::initTestData();
		return [
			[
				self::$standardArray,
				'a:17:{s:4:"true";b:1;s:5:"false";b:0;s:3:"int";i:42;s:4:"zero";i:0;s:6:"double";d:1' .
					'2.75;s:3:"inf";d:INF;s:4:"-inf";d:-INF;s:6:"string";s:6:"string";s:12:"empty string"' .
					';s:0:"";s:5:"array";a:6:{i:0;i:0;i:1;i:1;i:100;i:100;i:3;i:3;i:2;i:2;s:3:"foo";s:3:"' .
					'bar";}s:11:"empty array";a:0:{}s:6:"object";O:8:"stdClass":1:{s:3:"foo";s:3:"foo";}s' .
					':12:"empty object";O:8:"stdClass":0:{}s:4:"null";N;s:0:"";s:9:"empty key";i:42;i:42;' .
					's:128:"long key --------------------------------------------------------------------' .
					'---------------------------------------------------";s:8:"long key";}',
				[],
			],
			[
				[ 'pipe|key' => 'piped' ],
				'a:1:{s:8:"pipe|key";s:5:"piped";}',
				[],
			],
			[
				[ 'bang!key' => 'banged' ],
				'a:1:{s:8:"bang!key";s:6:"banged";}',
				[],
			],
			[
				[ 'nan' => NAN ],
				'a:1:{s:3:"nan";d:NAN;}',
				[],
			],
			[
				[ 'function' => self::$closure ],
				null,
				[
					[ LogLevel::ERROR, "PHP serialization failed: Serialization of 'Closure' is not allowed" ],
				],
			],
		];
	}

	public static function provideDecodePhpSerialize() {
		$ret = array_filter( self::provideEncodePhpSerialize(), static function ( $x ) {
			return $x[1] !== null;
		} );

		$ret[] = [
			null,
			'Bogus',
			[
				[
					LogLevel::ERROR, 'PHP unserialization failed: [unserialize error]'
				]
			],
		];
		$ret[] = [
			null,
			'O:8:"stdClass":1:{s:3:"foo";s:3:"bar";}',
			[
				[ LogLevel::ERROR, 'PHP unserialization failed (value was not an array)' ]
			],
		];

		return $ret;
	}

	/**
	 * @dataProvider provideEncodePhpSerialize
	 */
	public function testEncodePhpSerialize( $data, $encoded, $log ) {
		$logger = new TestLogger( true );
		PhpSessionSerializer::setLogger( $logger );
		$this->assertSame( $encoded, PhpSessionSerializer::encodePhpSerialize( $data ) );
		$this->assertSame( $log, $logger->getBuffer() );
	}

	/**
	 * @dataProvider provideDecodePhpSerialize
	 */
	public function testDecodePhpSerialize( $data, $encoded, $log ) {
		$logger = new TestLogger( true );
		PhpSessionSerializer::setLogger( $logger );
		if ( isset( $data['nan'] ) ) {
			$ret = PhpSessionSerializer::decodePhpSerialize( $encoded );
			$this->assertTrue( is_nan( $ret['nan'] ) );
		} else {
			$this->assertEquals( $data, PhpSessionSerializer::decodePhpSerialize( $encoded ) );
		}
		$this->assertSame( $log, $logger->getBuffer() );
	}

	public static function provideDecoders() {
		return [
			[ 'decode' ],
			[ 'decodePhp' ],
			[ 'decodePhpBinary' ],
			[ 'decodePhpSerialize' ],
		];
	}

	/**
	 * @dataProvider provideDecoders
	 */
	public function testDecoderTypeCheck( $method ) {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( '$data must be a string' );
		PhpSessionSerializer::$method( 1 );
	}

}
