<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Exception;
/**
 * The base class of all exceptions thrown by Propulsion.
 * @author     Hans Lellelid <hans@xmpl.org>
 * @version    $Revision$
 */
class PropulsionException extends \Exception
{
	/**
	 * @param     string|\Exception|null $message A message string, or (if called with a single
	 *            argument) the wrapped exception itself -- in which case the message is empty.
	 * @param     \Exception|null $previous
	 *
	 * @return    PropulsionException
	 */
	public function __construct($message = null, ?\Exception $previous = null)
	{
		if ($message instanceof \Exception) {
			$previous = $message;
			$message = '';
		}
		if ($previous !== null) {
			$message .= " [wrapped: " . $previous->getMessage() ."]";
			parent::__construct($message, 0, $previous);
		} else {
			parent::__construct($message);
		}
	}

	/**
	 * Get the previous Exception
	 * We can't override getPrevious() since it's final
	 *
	 * @return    \Throwable|null  The previous exception
	 */
	public function getCause(): \Exception|\Throwable|null
	{
		return $this->getPrevious();
	}
}
