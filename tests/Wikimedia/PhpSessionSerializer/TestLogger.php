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
