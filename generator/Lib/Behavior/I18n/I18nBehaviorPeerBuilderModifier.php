<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\I18n;

/**
 * Allows translation of text columns through transparent one-to-many relationship.
 * Modifier for the peer builder.
 *
 * @author     François Zaninotto
 * @version    $Revision$
 */
class I18nBehaviorPeerBuilderModifier
{
	protected I18nBehavior $behavior;

	public function __construct(I18nBehavior $behavior)
	{
		$this->behavior = $behavior;
	}

	public function staticConstants(): string
	{
		return "
/**
 * The default locale to use for translations
 * @var        string
 */
const DEFAULT_LOCALE = '{$this->behavior->getDefaultLocale()}';";
	}
}