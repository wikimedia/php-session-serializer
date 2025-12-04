<?php
/**
 * php-session-serializer
 *
 * Copyright (C) 2015 Brad Jorsch <bjorsch@wikimedia.org>
 *
 * @license GPL-2.0-or-later
 * @file
 * @author Brad Jorsch <bjorsch@wikimedia.org>
 */

namespace Wikimedia\PhpSessionSerializer;

class TestLogger extends \Psr\Log\AbstractLogger {
	/** @var bool */
	private $collect = false;
	/** @var array[] */
	private $buffer = [];

	/**
	 * @param bool $collect Whether to collect log messages
	 */
	public function __construct( $collect = false ) {
		$this->collect = $collect;
	}

	/**
	 * Return the collected log messages
	 * @return array
	 */
	public function getBuffer() {
		return $this->buffer;
	}

	/** @inheritDoc */
	public function log( $level, $message, array $context = [] ): void {
		$message = trim( $message );

		if ( $this->collect ) {
			$message = preg_replace(
				'/unserialize\(\): Error at offset 0 of [45] bytes$/',
				'[unserialize error]',
				$message
			);

			$this->buffer[] = [ $level, $message ];
		} else {
			echo "LOG[$level]: $message\n";
		}
	}
}
