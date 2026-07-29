<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Builder\OM\ColumnType;

use Propulsion\Generator\Builder\OM\ObjectBuilder;
use Propulsion\Generator\Model\Column;

/**
 * LOB columns (BLOB/VARBINARY/LONGVARBINARY) are stored internally as a PHP
 * stream resource. "resource" isn't a legal PHP type declaration, so `mixed`
 * is used for the property/getter/setter signatures.
 *
 * No buildHydrateExpr()/buildValueExpr() override: a LOB column is always
 * lazy-loaded (see `ObjectBuilder::addHydrate()`'s `isLazyLoad()` skip and
 * `addLazyLoader()`, an entirely separate mechanism), so it's never actually
 * reached by hydrate()'s per-column loop; buildCriteria()/
 * buildPkeyCriteria() have never had a special case for it either -- the
 * stream resource is passed straight through to Criteria::add() and handled
 * at PDO bind time instead.
 */
class LobHandler extends ColumnTypeHandler
{
	public function applies(Column $col): bool
	{
		return $col->isLobType();
	}

	public function getPhpTypeHint(Column $col, ObjectBuilder $builder): ?string
	{
		return 'mixed';
	}

	public function buildCastExpr(Column $col, string $varExpr): ?string
	{
		return $varExpr;
	}
}
