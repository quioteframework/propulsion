<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior;

/**
 * Changes the coding standard of Propulsion generated Model classes
 *  - Opening brackets always use newline, e.g.
 *     if ($foo) {
 *       ...
 *     } else {
 *       ...
 *     }
 *    Becomes:
 *     if ($foo)
 *     {
 *       ...
 *     }
 *     else
 *     {
 *       ...
 *     }
 *  - closing comments are removed, e.g.
 *     } // save()
 *    Becomes:
 *     }
 *   - tabs are replaced by 2 whitespaces
 *   - comments are stripped (optional)
 *
 * @author     François Zaninotto
 * @version    $Revision$
 */

 use Propulsion\Generator\Model\Behavior;
class AlternativeCodingStandardsBehavior extends Behavior
{
	// default parameters value
  /** @var array<string,string|int> */
  protected $parameters = array(
  	'brackets_newline'        => 'true',
  	'remove_closing_comments' => 'true',
  	'use_whitespace'          => 'true',
  	'tab_size'                => 2,
  	'strip_comments'          => 'false'
  );

	public function objectFilter(string &$script): void
	{
		$this->filter($script);
	}

	public function extensionObjectFilter(string &$script): void
	{
		$this->filter($script);
	}

	public function queryFilter(string &$script): void
	{
		$this->filter($script);
	}

	public function extensionQueryFilter(string &$script): void
	{
		$this->filter($script);
	}

	public function peerFilter(string &$script): void
	{
		$this->filter($script);
	}

	public function extensionPeerFilter(string &$script): void
	{
		$this->filter($script);
	}

	public function tableMapFilter(string &$script): void
	{
		$this->filter($script);
	}

	/**
	 * Transform the coding standards of a PHP sourcecode string
	 *
	 * @param string $script A script string to be filtered, passed as reference
	 */
	protected function filter(string &$script): void
	{
		$filter = array();
		if($this->getParameter('brackets_newline') == 'true') {
			$filter['#^(\t*)\}\h(else|elseif|catch)(.*)\h\{$#m'] = "$1}
$1$2$3
$1{";
			$filter['#^(\t*)(\w.*)\h\{$#m'] = "$1$2
$1{";
		}
		if ($this->getParameter('remove_closing_comments') == 'true') {
			$filter['#^(\t*)} //.*$#m'] = "$1}";
		}
		if ($this->getParameter('use_whitespace') == 'true') {
			$filter['#\t#'] = str_repeat(' ', $this->getParameter('tab_size'));
		}

		$script = preg_replace(array_keys($filter), array_values($filter), $script) ?? $script;

		if ($this->getParameter('strip_comments') == 'true') {
			$script = self::stripComments($script);
		}
	}

	/**
	 * Remove inline and codeblock comments from a PHP code string
	 * @param  string $code The input code
	 * @return string       The input code, without comments
	 */
	public static function stripComments($code)
	{
		$output  = '';
		$commentTokens = array(T_COMMENT, T_DOC_COMMENT);
		foreach (token_get_all($code) as $token) {
			if (is_array($token)) {
		    if (in_array($token[0], $commentTokens)) continue;
				$token = $token[1];
		  }
		  $output .= $token;
		}

		return $output;
	}
}